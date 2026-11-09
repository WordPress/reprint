<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class RemoteFileIndexCursorState {

    /** @var string|null Remote file-index cursor. */
    public ?string $cursor = null;

    /** @var int|null Next-index bytes belonging to complete parts; null adopts an older index on first resume. */
    public ?int $bytes_written = null;

    /**
     * @var list<string> With `--include=:wp-core:`, directories under ABSPATH
     * that a selected symlink targets. They are pulled in full, like an
     * include. Computed from the next remote index during the index stage.
     */
    public array $wp_core_followed_target_path_prefixes = [];

    /**
     * @param array $data {
     *     Saved index download position.
     *     @type string|null $cursor        Source cursor after complete parts.
     *     @type int|null    $bytes_written Matching local index byte offset; absent in older checkpoints.
     *     @type list<string> $wp_core_followed_target_path_prefixes Followed targets under ABSPATH; absent in older checkpoints.
     * }
     */
    public static function from_array(array $data): self
    {
        $state = new self();
        $data += ['bytes_written' => null, 'wp_core_followed_target_path_prefixes' => []];
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->cursor = $data['cursor'];
        $state->bytes_written = $data['bytes_written'];
        $prefixes = $data['wp_core_followed_target_path_prefixes'];
        if (!is_array($prefixes) || $prefixes !== array_values(array_filter($prefixes, 'is_string'))) {
            throw new \UnexpectedValueException(
                'RemoteFileIndexCursorState wp_core_followed_target_path_prefixes must be a list of strings.'
            );
        }
        $state->wp_core_followed_target_path_prefixes = $prefixes;
        return $state;
    }

    /**
     * @return array {
     *     Matching source and local positions after complete multipart parts.
     *     @type string|null $cursor        Source cursor.
     *     @type int|null    $bytes_written Next-index byte offset; null before the first download.
     *     @type list<string> $wp_core_followed_target_path_prefixes Followed targets under ABSPATH.
     * }
     */
    public function to_array(): array
    {
        return [
            'cursor' => $this->cursor,
            'bytes_written' => $this->bytes_written,
            'wp_core_followed_target_path_prefixes' => $this->wp_core_followed_target_path_prefixes,
        ];
    }
}
