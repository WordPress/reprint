<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class FileDiffProgressState {

    /** @var int Byte offset into the next remote index while diffing. */
    public int $next_remote_index_byte_offset = 0;

    /** @var string|null Last remote index entry path consumed at the current next remote index byte offset. */
    public ?string $last_consumed_remote_index_entry_path = null;

    /** @var string|null Last next remote index entry processed before the current byte offset. */
    public ?string $last_processed_next_remote_index_entry_path = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->next_remote_index_byte_offset =
            $data['next_remote_index_byte_offset'];
        $state->last_consumed_remote_index_entry_path =
            $data['last_consumed_remote_index_entry_path'];
        $state->last_processed_next_remote_index_entry_path =
            $data['last_processed_next_remote_index_entry_path'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'next_remote_index_byte_offset' =>
                $this->next_remote_index_byte_offset,
            'last_consumed_remote_index_entry_path' =>
                $this->last_consumed_remote_index_entry_path,
            'last_processed_next_remote_index_entry_path' =>
                $this->last_processed_next_remote_index_entry_path,
        ];
    }
}
