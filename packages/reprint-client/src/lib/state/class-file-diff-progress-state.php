<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class FileDiffProgressState {

    /** @var array<string,mixed>|null Cursor for the active comparison processor. */
    public ?array $processor_cursor = null;

    /** @var int Fetch-list byte offset covered by the saved diff cursor. */
    public int $fetch_list_byte_offset = 0;

    /** @var int Pull-index-WAL byte offset covered by the saved diff cursor. */
    public int $pull_index_wal_byte_offset = 0;

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        if (!is_array($data['processor_cursor']) && $data['processor_cursor'] !== null) {
            throw new \UnexpectedValueException(
                'FileDiffProgressState processor_cursor must be an array or null.'
            );
        }
        $state->processor_cursor = $data['processor_cursor'];
        $state->fetch_list_byte_offset = $data['fetch_list_byte_offset'];
        $state->pull_index_wal_byte_offset =
            $data['pull_index_wal_byte_offset'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'processor_cursor' => $this->processor_cursor,
            'fetch_list_byte_offset' => $this->fetch_list_byte_offset,
            'pull_index_wal_byte_offset' =>
                $this->pull_index_wal_byte_offset,
        ];
    }
}
