<?php
/** Records native PHP behavior before choosing the Windows filesystem calls. */
$cases = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
printf("Windows PHP %s: FFI class=%s, ffi.enable=%s, cwd=%s\n", PHP_VERSION, class_exists('FFI') ? 'yes' : 'no', ini_get('ffi.enable'), getcwd());
$native = FFI::cdef('
    typedef unsigned short WCHAR;
    typedef unsigned long DWORD;
    typedef void *HANDLE;
    HANDLE __stdcall CreateFileW(const WCHAR *, DWORD, DWORD, void *, DWORD, DWORD, HANDLE);
    DWORD __stdcall GetFinalPathNameByHandleW(HANDLE, WCHAR *, DWORD, DWORD);
    int __stdcall ReadFile(HANDLE, void *, DWORD, DWORD *, void *);
    int __stdcall CloseHandle(HANDLE);
    DWORD __stdcall GetLastError(void);
', 'kernel32.dll');
foreach ($cases as $name => $case) {
    if (isset($case['error']) || isset($case['files']) || is_array($case['source'])) {
        continue;
    }
    $path = str_replace('/', '\\', $case['source']);
    if (substr($case['destination'], -10) === '/hello.txt') {
        $path .= '\\hello.txt';
    }
    $wide_bytes = mb_convert_encoding($path, 'UTF-16LE', 'UTF-8') . "\0\0";
    $wide_path = $native->new('WCHAR[' . (strlen($wide_bytes) / 2) . ']');
    FFI::memcpy($wide_path, $wide_bytes, strlen($wide_bytes));
    printf("WIN32: opening %s\n", $name);
    $handle = $native->CreateFileW($wide_path, 0x80000000, 7, null, 3, 0x02000000, null);
    printf("WIN32: %s handle returned\n", $name);
    if (FFI::cast('intptr_t', $handle)->cdata === -1) {
        printf("WIN32: %s open error %d\n", $name, $native->GetLastError());
    } else {
        try {
            $final_path = $native->new('WCHAR[32768]');
            $length = $native->GetFinalPathNameByHandleW($handle, $final_path, 32768, 0);
            printf("WIN32: %s final path returned %d\n", $name, $length);
            $buffer = $native->new('char[64]');
            $read = $native->new('DWORD');
            $read_ok = $native->ReadFile($handle, $buffer, 64, FFI::addr($read), null);
            printf("WIN32: %s read returned %d\n", $name, $read->cdata);
            printf("WIN32: %s\n", json_encode([
                'case' => $name,
                'final_path' => $length ? mb_convert_encoding(FFI::string(FFI::cast('char *', FFI::addr($final_path[0])), $length * 2), 'UTF-8', 'UTF-16LE') : false,
                'read_matches' => $read_ok && FFI::string($buffer, $read->cdata) === $case['content'],
            ], JSON_UNESCAPED_SLASHES));
        } finally {
            $native->CloseHandle($handle);
        }
    }
    clearstatcache();
    $stat = @lstat($path);
    printf("NATIVE: %s\n", json_encode([
        'case' => $name,
        'realpath' => @realpath($path),
        'stat' => $stat === false ? false : ['mode' => $stat['mode'], 'size' => $stat['size']],
        'read_matches' => @file_get_contents($path) === $case['content'],
        'device_prefix_read_matches' => @file_get_contents(str_replace('\\\\?\\', '\\\\.\\', $path)) === $case['content'],
    ], JSON_UNESCAPED_SLASHES));
}
