<?php

namespace WordPress\Reprint\Server;

use InvalidArgumentException;
use RuntimeException;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Filesystem errors are API values, not HTML.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- PHP defines the stream wrapper method signatures.
// phpcs:disable PHPCompatibility.Classes.NewClasses.ffiFound -- available() rejects PHP below 7.4 before any native call.

/**
 * Read-only Windows stream wrapper for exact filenames, including trailing dots.
 *
 * PHP's ordinary file wrapper normalizes some names before opening them. The
 * index and the reader must instead inspect and open the same literal path.
 * Native handles stay open across fread()/fseek() calls; no file is buffered.
 * FFI is optional for ordinary paths and never bypasses PHP open_basedir.
 */
final class WindowsFilesystem {
    /** @var resource|null PHP supplies the stream context. */
    public $context;
    /** @var mixed Kernel32 FFI binding, or null when unavailable. */
    private static $api;
    /** @var bool Whether native initialization has been attempted. */
    private static $initialized = false;
    /** @var mixed|null Open file or directory-search handle. */
    private $handle;
    /** @var mixed Directory-search result retained until the next readdir(). */
    private $entry;
    /** @var bool Whether the first directory-search result is still pending. */
    private $first_entry = false;
    /** @var string Directory URI used by rewinddir(). */
    private $directory_uri = '';
    /** @var int File byte offset. */
    private $offset = 0;
    /** @var bool Whether ReadFile reached EOF. */
    private $eof = false;

    /** Registers native reads only when the host permits FFI and unrestricted file access. */
    public static function available(): bool {
        if (PHP_OS !== 'WINNT' || ini_get('open_basedir') !== '') {
            return false;
        }
        if (!self::$initialized) {
            self::$initialized = true;
            if (PHP_VERSION_ID < 70400 || PHP_INT_SIZE !== 8 || !extension_loaded('FFI') || !function_exists('mb_convert_encoding')) {
                return false;
            }
            try {
                self::$api = \FFI::cdef('
                    typedef unsigned short WCHAR;
                    typedef unsigned long DWORD;
                    typedef void *HANDLE;
                    typedef struct { DWORD low; DWORD high; } FILETIME;
                    typedef struct {
                        DWORD attributes; FILETIME creation; FILETIME access; FILETIME write;
                        DWORD volume; DWORD size_high; DWORD size_low; DWORD links;
                        DWORD index_high; DWORD index_low;
                    } FILE_INFO;
                    typedef struct {
                        DWORD attributes; FILETIME creation; FILETIME access; FILETIME write;
                        DWORD size_high; DWORD size_low; DWORD reserved0; DWORD reserved1;
                        WCHAR name[260]; WCHAR alternate_name[14];
                    } FIND_DATA;
                    HANDLE CreateFileW(const WCHAR *, DWORD, DWORD, void *, DWORD, DWORD, HANDLE);
                    int CloseHandle(HANDLE);
                    DWORD GetLastError(void);
                    DWORD GetFullPathNameW(const WCHAR *, DWORD, WCHAR *, WCHAR **);
                    DWORD GetLongPathNameW(const WCHAR *, WCHAR *, DWORD);
                    DWORD GetFinalPathNameByHandleW(HANDLE, WCHAR *, DWORD, DWORD);
                    int GetFileInformationByHandle(HANDLE, FILE_INFO *);
                    int GetFileInformationByHandleEx(HANDLE, int, void *, DWORD);
                    int DeviceIoControl(HANDLE, DWORD, void *, DWORD, void *, DWORD, DWORD *, void *);
                    int ReadFile(HANDLE, void *, DWORD, DWORD *, void *);
                    int SetFilePointerEx(HANDLE, long long, long long *, DWORD);
                    HANDLE FindFirstFileW(const WCHAR *, FIND_DATA *);
                    int FindNextFileW(HANDLE, FIND_DATA *);
                    int FindClose(HANDLE);
                ', 'kernel32.dll');
                if (!stream_wrapper_register('reprint-windows', self::class)) {
                    self::$api = null;
                }
            } catch (\Throwable $error) {
                self::$api = null;
            }
        }
        return self::$api !== null;
    }

    /**
     * Resolves user input once against the native source process, retaining links.
     *
     * GetLongPathName expands spelling and case without replacing a junction or
     * symlink with its target. Only a volume namespace root is resolved by handle;
     * descendants must retain their names for the index's no-follow checks.
     */
    public static function resolve_input(string $path): string {
        self::assert_available();
        if ($path === '' || strpos($path, "\0") !== false) {
            throw new InvalidArgumentException('Windows path must be non-empty and contain no NUL bytes.');
        }
        $buffer = self::$api->new('WCHAR[32768]');
        // GetFullPathNameW trims trailing dots even with a literal prefix.
        // Literal inputs are already absolute; resolving them again selects a
        // different file when an ordinary sibling exists.
        if (substr($path, 0, 4) === '\\\\?\\') {
            $absolute = $path;
        } else {
            $length = self::$api->GetFullPathNameW(self::wide($path), 32768, $buffer, null);
            $absolute = self::path_result($buffer, $length, $path);
        }
        // Reject device objects before opening any handle. These are the file
        // namespace roots supported by the migration, not arbitrary devices.
        if (preg_match('~^\\\\\\\\[?.]\\\\~', $absolute)) {
            $tail = substr($absolute, 4);
            if (preg_match('~^[a-zA-Z]:\\\\~', $tail)) {
                $absolute = $tail;
            } elseif (strncasecmp($tail, 'UNC\\', 4) === 0) {
                $absolute = '\\\\' . substr($tail, 4);
            } elseif (preg_match('~^(Volume\{[0-9a-f-]+\}|GLOBALROOT\\\\Device\\\\HarddiskVolume[0-9]+)\\\\~i', $tail, $match)) {
                $root = substr($absolute, 0, 4) . $match[0];
                $resolved_root = self::realpath_native($root);
                if ($resolved_root === false) {
                    throw new RuntimeException('Cannot resolve Windows volume root: ' . $root);
                }
                $absolute = rtrim(str_replace('/', '\\', $resolved_root), '\\') . '\\' . substr($tail, strlen($match[0]));
            } else {
                throw new InvalidArgumentException('Windows device names cannot select migration files: ' . $path);
            }
        }
        $canonical = normalize_path_separators($absolute);
        assert_valid_path($canonical, 'Windows source path');
        $native = self::native_path($canonical);
        $length = self::$api->GetLongPathNameW(self::wide($native), $buffer, 32768);
        if ($length === 0) {
            // Excludes and paths recorded by an earlier pull may no longer exist.
            // Retain their lexical spelling; the index decides whether absence
            // means deletion or an invalid new selection.
            $error = self::$api->GetLastError();
            if ($error !== 2 && $error !== 3) {
                throw new RuntimeException('Cannot resolve Windows path ' . $path . '; Windows error ' . $error);
            }
            return trim_right_slash($canonical);
        }
        return self::canonical_path(self::path_result($buffer, $length, $path));
    }

    /** Resolves filesystem links while retaining exact Windows filename bytes. */
    public static function realpath(string $path) {
        return self::realpath_native(self::native_path($path));
    }

    /** Reads the stored target of a Windows symlink or junction without following it. */
    public static function readlink(string $path) {
        $handle = self::open(self::native_path($path), 0, true);
        if ($handle === null) {
            return false;
        }
        try {
            $buffer = self::$api->new('char[16384]');
            $read = self::$api->new('DWORD');
            // FSCTL_GET_REPARSE_POINT reads metadata only. Never accept a control
            // code from callers or follow the link while obtaining its target.
            if (!self::$api->DeviceIoControl($handle, 0x000900A8, null, 0, $buffer, 16384, \FFI::addr($read), null)) {
                throw new RuntimeException('Cannot read Windows link ' . $path . '; Windows error ' . self::$api->GetLastError());
            }
            $bytes = \FFI::string($buffer, $read->cdata);
            $header = unpack('Vtag/vlength/vreserved/voffset/vbytes', $bytes);
            if ($header['tag'] !== 0xA000000C && $header['tag'] !== 0xA0000003) {
                return false;
            }
            $start = ( $header['tag'] === 0xA000000C ? 20 : 16 ) + $header['offset'];
            $target = mb_convert_encoding(substr($bytes, $start, $header['bytes']), 'UTF-8', 'UTF-16LE');
            if (strncasecmp($target, '\\??\\UNC\\', 8) === 0) {
                $target = '\\\\' . substr($target, 8);
            } elseif (substr($target, 0, 4) === '\\??\\') {
                $target = substr($target, 4);
            }
            return is_absolute_path($target) ? normalize_path_separators($target) : str_replace('\\', '/', $target);
        } finally {
            self::$api->CloseHandle($handle);
        }
    }

    /** Opens a file read-only; PHP retains this wrapper across bounded reads. */
    public function stream_open(string $uri, string $mode, int $options, ?string &$opened_path): bool {
        if ($mode !== 'r' && $mode !== 'rb') {
            throw new InvalidArgumentException('The Windows source stream is read-only; got mode ' . $mode);
        }
        $path = self::decode_uri($uri);
        $this->handle = self::open(self::native_path($path), 0x80000000, false);
        return $this->handle !== null;
    }

    /** Reads at most the bytes requested by PHP's stream layer. */
    public function stream_read(int $count): string {
        $buffer = self::$api->new('char[' . $count . ']');
        $read = self::$api->new('DWORD');
        if (!self::$api->ReadFile($this->handle, $buffer, $count, \FFI::addr($read), null)) {
            throw new RuntimeException('Cannot read Windows source file; Windows error ' . self::$api->GetLastError());
        }
        $this->offset += $read->cdata;
        $this->eof = $read->cdata < $count;
        return \FFI::string($buffer, $read->cdata);
    }

    /** Repositions the retained file handle when resuming a transfer. */
    public function stream_seek(int $offset, int $whence): bool {
        $position = self::$api->new('long long');
        if (!self::$api->SetFilePointerEx($this->handle, $offset, \FFI::addr($position), $whence)) {
            return false;
        }
        $this->offset = $position->cdata;
        $this->eof = false;
        return true;
    }

    /** Returns the current native file offset. */
    public function stream_tell(): int {
        return $this->offset;
    }

    /** Reports whether the last read reached the end of the file. */
    public function stream_eof(): bool {
        return $this->eof;
    }

    /** Releases the file handle once; close is also safe after an unsuccessful open. */
    public function stream_close(): void {
        if ($this->handle !== null) {
            self::$api->CloseHandle($this->handle);
            $this->handle = null;
        }
    }

    /** Returns metadata for the retained file handle. */
    public function stream_stat(): array {
        return self::handle_stat($this->handle);
    }

    /** Returns stat/lstat metadata without letting PHP normalize the filename. */
    public function url_stat(string $uri, int $flags) {
        $handle = self::open(self::native_path(self::decode_uri($uri)), 0, (bool) ( $flags & STREAM_URL_STAT_LINK ));
        if ($handle === null) {
            return false;
        }
        try {
            return self::handle_stat($handle);
        } finally {
            self::$api->CloseHandle($handle);
        }
    }

    /** Opens one directory search; scandir retains its existing sorted-name contract. */
    public function dir_opendir(string $uri, int $options): bool {
        $this->directory_uri = $uri;
        $this->entry = self::$api->new('FIND_DATA');
        $pattern = rtrim(self::native_path(self::decode_uri($uri)), '\\') . '\\*';
        $this->handle = self::$api->FindFirstFileW(self::wide($pattern), \FFI::addr($this->entry));
        if (self::invalid_handle($this->handle)) {
            $error = self::$api->GetLastError();
            $this->handle = null;
            if ($error === 2) {
                return true;
            }
            throw new RuntimeException('Cannot list Windows directory ' . $uri . '; Windows error ' . $error);
        }
        $this->first_entry = true;
        return true;
    }

    /** Returns one native directory name, preserving its case and trailing characters. */
    public function dir_readdir() {
        if ($this->handle === null) {
            return false;
        }
        if (!$this->first_entry && !self::$api->FindNextFileW($this->handle, \FFI::addr($this->entry))) {
            $error = self::$api->GetLastError();
            if ($error !== 18) {
                throw new RuntimeException('Cannot read next Windows directory entry; Windows error ' . $error);
            }
            return false;
        }
        $this->first_entry = false;
        $length = 0;
        while ($length < 260 && $this->entry->name[$length] !== 0) {
            ++$length;
        }
        return self::utf8($this->entry->name, $length);
    }

    /** Starts this directory search again when PHP requests rewinddir(). */
    public function dir_rewinddir(): bool {
        $this->dir_closedir();
        return $this->dir_opendir($this->directory_uri, 0);
    }

    /** Releases a directory search independently of ordinary file handles. */
    public function dir_closedir(): bool {
        if ($this->handle !== null) {
            self::$api->FindClose($this->handle);
            $this->handle = null;
        }
        return true;
    }

    /** Opens only existing filesystem entries, optionally without following the final link. */
    private static function open(string $native_path, int $access, bool $no_follow) {
        self::assert_available();
        $handle = self::$api->CreateFileW(self::wide($native_path), $access, 7, null, 3, 0x02000000 | ( $no_follow ? 0x00200000 : 0 ), null);
        if (self::invalid_handle($handle)) {
            $error = self::$api->GetLastError();
            if ($error === 2 || $error === 3) {
                return null;
            }
            throw new RuntimeException('Cannot open Windows path ' . $native_path . '; Windows error ' . $error);
        }
        return $handle;
    }

    /** Reads PHP-compatible stat fields from the actual handle, not a normalized alias. */
    private static function handle_stat($handle): array {
        $info = self::$api->new('FILE_INFO');
        if (!self::$api->GetFileInformationByHandle($handle, \FFI::addr($info))) {
            throw new RuntimeException('Cannot inspect Windows file; Windows error ' . self::$api->GetLastError());
        }
        $tag = self::$api->new('DWORD[2]');
        if (!self::$api->GetFileInformationByHandleEx($handle, 9, $tag, 8)) {
            throw new RuntimeException('Cannot inspect Windows reparse tag; Windows error ' . self::$api->GetLastError());
        }
        $is_link = $tag[1] === 0xA000000C || $tag[1] === 0xA0000003;
        $mode = $is_link ? 0120000 : ( ( $info->attributes & 0x10 ) ? 0040000 : 0100000 );
        // Windows PHP exposes creation time as ctime. Keep that same convention
        // in the index and post-read checks; changing clocks would invalidate
        // existing change records. Same-size edits can escape these fields.
        $stat = [
            'dev' => $info->volume,
            'ino' => $info->index_low,
            'mode' => $mode | ( ( $info->attributes & 1 ) ? 0444 : 0666 ),
            'nlink' => $info->links,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => (int) ( $info->size_high * 4294967296 + $info->size_low ),
            'atime' => self::timestamp($info->access),
            'mtime' => self::timestamp($info->write),
            'ctime' => self::timestamp($info->creation),
            'blksize' => -1,
            'blocks' => -1,
        ];
        return array_merge(array_values($stat), $stat);
    }

    /** Resolves a literal native path through its handle, following links intentionally. */
    private static function realpath_native(string $path) {
        $handle = self::open($path, 0, false);
        if ($handle === null) {
            return false;
        }
        try {
            $buffer = self::$api->new('WCHAR[32768]');
            $length = self::$api->GetFinalPathNameByHandleW($handle, $buffer, 32768, 0);
            return self::canonical_path(self::path_result($buffer, $length, $path));
        } finally {
            self::$api->CloseHandle($handle);
        }
    }

    /** Converts canonical drive/share paths to literal native paths; no device objects are accepted. */
    private static function native_path(string $path): string {
        self::assert_available();
        assert_valid_path($path, 'Windows source path');
        $path = normalize_path_separators($path);
        if (preg_match('~^[A-Z]:/~', $path)) {
            return '\\\\?\\' . str_replace('/', '\\', $path);
        }
        if (windows_share_root($path) !== null) {
            return '\\\\?\\UNC\\' . str_replace('/', '\\', substr($path, 2));
        }
        throw new InvalidArgumentException('Windows device names cannot select migration files: ' . $path);
    }

    /** Removes only the native namespace prefix; filename bytes remain untouched. */
    private static function canonical_path(string $path): string {
        if (strncasecmp($path, '\\\\?\\UNC\\', 8) === 0) {
            $path = '\\\\' . substr($path, 8);
        } elseif (substr($path, 0, 4) === '\\\\?\\') {
            $path = substr($path, 4);
        }
        $path = trim_right_slash(normalize_path_separators($path));
        assert_valid_path($path, 'Resolved Windows path');
        return $path;
    }

    /** Rejects native calls if the host disables FFI or sets open_basedir later in the request. */
    private static function assert_available(): void {
        if (!self::available()) {
            throw new RuntimeException('Exact Windows paths require 64-bit PHP 7.4+ with FFI and mbstring enabled and open_basedir unset. Use an ordinary source path or enable those extensions for the migration.');
        }
    }

    /** Decodes the private wrapper URI without PHP URL or path normalization. */
    private static function decode_uri(string $uri): string {
        $path = base64_decode(substr($uri, strlen('reprint-windows://')), true);
        if ($path === false) {
            throw new InvalidArgumentException('Windows source stream URI contains invalid base64.');
        }
        return $path;
    }

    /** Encodes a bounded Windows UTF-16 path for a native call. */
    private static function wide(string $value) {
        if (strpos($value, "\0") !== false || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Windows paths must be valid UTF-8 without NUL bytes.');
        }
        $bytes = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8') . "\0\0";
        if (strlen($bytes) > 65536) {
            throw new InvalidArgumentException('Windows path exceeds 32767 UTF-16 code units.');
        }
        $buffer = self::$api->new('WCHAR[' . ( strlen($bytes) / 2 ) . ']');
        \FFI::memcpy($buffer, $bytes, strlen($bytes));
        return $buffer;
    }

    /** Validates the length returned by a native path lookup before reading its buffer. */
    private static function path_result($buffer, int $length, string $path): string {
        if ($length === 0 || $length >= 32768) {
            throw new RuntimeException('Cannot resolve Windows path ' . $path . '; Windows error ' . self::$api->GetLastError() . ', returned length ' . $length);
        }
        return self::utf8($buffer, $length);
    }

    /** Decodes exactly the UTF-16 code units reported by the Windows API. */
    private static function utf8($buffer, int $length): string {
        return mb_convert_encoding(\FFI::string(self::$api->cast('char *', \FFI::addr($buffer[0])), $length * 2), 'UTF-8', 'UTF-16LE');
    }

    /** Compares an opaque handle without dereferencing its numeric Windows value. */
    private static function invalid_handle($handle): bool {
        return self::$api->cast('intptr_t *', \FFI::addr($handle))[0] === -1;
    }

    /** Converts a Windows FILETIME to the Unix seconds used by PHP stat(). */
    private static function timestamp($time): int {
        return (int) floor(( $time->high * 4294967296 + $time->low ) / 10000000 - 11644473600);
    }
}
