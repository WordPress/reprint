<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class FileDiffProgressState {

    /**
     * File-index diff cursor.
     *
     * @var array{
     *     old_index_byte_offset:int,
     *     new_index_byte_offset:int,
     *     preceding_new_index_entry_path_b64:string|null
     * }
     */
    public array $index_diff_cursor = [
        'old_index_byte_offset' => 0,
        'new_index_byte_offset' => 0,
        'preceding_new_index_entry_path_b64' => null,
    ];

    /** @var int Fetch-list byte offset covered by the saved diff cursor. */
    public int $fetch_list_byte_offset = 0;

    /** @var int Pull-index-WAL byte offset covered by the saved diff cursor. */
    public int $pull_index_wal_byte_offset = 0;

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        \reprint_assert_state_keys(
            $data['index_diff_cursor'],
            array_keys($state->index_diff_cursor),
            self::class . ' index_diff_cursor'
        );
        $state->index_diff_cursor = $data['index_diff_cursor'];
        $state->fetch_list_byte_offset = $data['fetch_list_byte_offset'];
        $state->pull_index_wal_byte_offset =
            $data['pull_index_wal_byte_offset'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'index_diff_cursor' => $this->index_diff_cursor,
            'fetch_list_byte_offset' => $this->fetch_list_byte_offset,
            'pull_index_wal_byte_offset' =>
                $this->pull_index_wal_byte_offset,
        ];
    }
}
