<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class RemoteFileIndexState {

    /** @var string|null Remote file-index cursor. */
    public ?string $cursor = null;
    /** Next remote index bytes which are safe to keep when resuming from cursor. */
    public int $next_remote_index_byte_offset = 0;
    /**
     * @var array|null JSON-safe request identity retained until traversal completion.
     * @phpstan-var array{
     *     list_directory_b64:string,
     *     requested_directories_b64:list<string>,
     *     follow_symlinks:bool,
     *     include_caches:bool
     * }|null
     */
    public ?array $active_traversal = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        if (
            $data['cursor'] !== null
            && ( !is_string($data['cursor']) || $data['cursor'] === '' )
        ) {
            throw new \UnexpectedValueException(
                self::class . ' remote file-index cursor must be a non-empty string or null.'
            );
        }
        $state->cursor = $data['cursor'];
        if (
            !is_int($data['next_remote_index_byte_offset'])
            || $data['next_remote_index_byte_offset'] < 0
        ) {
            throw new \UnexpectedValueException(
                self::class . ' next remote index byte offset must be a non-negative integer.'
            );
        }
        $state->next_remote_index_byte_offset =
            $data['next_remote_index_byte_offset'];
        $state->active_traversal = self::validate_active_traversal(
            $data['active_traversal']
        );
        if ($state->cursor !== null && $state->active_traversal === null) {
            throw new \UnexpectedValueException(
                self::class . ' remote file-index cursor requires an active traversal.'
            );
        }
        return $state;
    }

    /**
     * Retains one traversal request as base64-safe state before network I/O.
     *
     * @param string   $list_directory       Raw list_dir request value.
     * @param string[] $requested_directories Raw directory[] request values.
     * @param bool     $follow_symlinks      Whether links may leave requested roots.
     * @param bool     $include_caches       Whether cache paths are included.
     */
    public function start_traversal(
        string $list_directory,
        array $requested_directories,
        bool $follow_symlinks,
        bool $include_caches
    ): void {
        $this->active_traversal = self::validate_active_traversal([
            'list_directory_b64' => base64_encode($list_directory),
            'requested_directories_b64' => array_map(
                'base64_encode',
                $requested_directories
            ),
            'follow_symlinks' => $follow_symlinks,
            'include_caches' => $include_caches,
        ]);
        $this->cursor = null;
    }

    /**
     * Returns the active traversal with raw request paths.
     *
     * @return array|null {
     *     Active request, or null before one starts or after it completes.
     *
     *     @type string   $list_directory       Raw list_dir value.
     *     @type string[] $requested_directories Raw directory[] values.
     *     @type bool     $follow_symlinks      Whether links may leave roots.
     *     @type bool     $include_caches       Whether caches are included.
     * }
     */
    public function active_traversal_request(): ?array
    {
        if ($this->active_traversal === null) {
            return null;
        }
        return [
            'list_directory' => base64_decode(
                $this->active_traversal['list_directory_b64'],
                true
            ),
            'requested_directories' => array_map(
                static function (string $path): string {
                    return (string) base64_decode($path, true);
                },
                $this->active_traversal['requested_directories_b64']
            ),
            'follow_symlinks' => $this->active_traversal['follow_symlinks'],
            'include_caches' => $this->active_traversal['include_caches'],
        ];
    }

    public function to_array(): array
    {
        return [
            'cursor' => $this->cursor,
            'next_remote_index_byte_offset' =>
                $this->next_remote_index_byte_offset,
            'active_traversal' => $this->active_traversal,
        ];
    }

    /** @return array|null Validated JSON-safe active traversal. */
    private static function validate_active_traversal($traversal): ?array
    {
        if ($traversal === null) {
            return null;
        }
        if (
            !is_array($traversal)
            || array_keys($traversal) !== [
                'list_directory_b64',
                'requested_directories_b64',
                'follow_symlinks',
                'include_caches',
            ]
            || !is_bool($traversal['follow_symlinks'])
            || !is_bool($traversal['include_caches'])
            || !self::is_encoded_path($traversal['list_directory_b64'])
            || !is_array($traversal['requested_directories_b64'])
            || $traversal['requested_directories_b64'] === []
            || array_values($traversal['requested_directories_b64'])
                !== $traversal['requested_directories_b64']
        ) {
            throw new \UnexpectedValueException(
                self::class . ' active traversal has invalid fields.'
            );
        }
        foreach ($traversal['requested_directories_b64'] as $path) {
            if (!self::is_encoded_path($path)) {
                throw new \UnexpectedValueException(
                    self::class . ' active traversal contains an invalid requested path.'
                );
            }
        }
        return $traversal;
    }

    /** Reports whether a value is canonical base64 for a non-empty absolute path. */
    private static function is_encoded_path($encoded_path): bool
    {
        if (!is_string($encoded_path)) {
            return false;
        }
        $path = base64_decode($encoded_path, true);
        return is_string($path)
            && $path !== ''
            && $path[0] === '/'
            && base64_encode($path) === $encoded_path;
    }
}
