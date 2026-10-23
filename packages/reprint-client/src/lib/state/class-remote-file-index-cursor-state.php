<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class RemoteFileIndexCursorState {

    /** @var string|null Remote file-index cursor. */
    public ?string $cursor = null;

    /** @var int|null Next-index bytes belonging to complete parts; null adopts an older index on first resume. */
    public ?int $bytes_written = null;

    /**
     * @param array $data {
     *     Saved index download position.
     *     @type string|null $cursor        Source cursor after complete parts.
     *     @type int|null    $bytes_written Matching local index byte offset; absent in older checkpoints.
     * }
     */
    public static function from_array(array $data): self
    {
        $state = new self();
        $data += ['bytes_written' => null];
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->cursor = $data['cursor'];
        $state->bytes_written = $data['bytes_written'];
        return $state;
    }

    /**
     * @return array {
     *     Matching source and local positions after complete multipart parts.
     *     @type string|null $cursor        Source cursor.
     *     @type int|null    $bytes_written Next-index byte offset; null before the first download.
     * }
     */
    public function to_array(): array
    {
        return ['cursor' => $this->cursor, 'bytes_written' => $this->bytes_written];
    }
}
