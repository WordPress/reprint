<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class FetchListProgressState {

    /** @var int Current byte offset into the fetch-list file. */
    public int $offset = 0;

    /** @var int Next byte offset after the current batch. */
    public int $next_offset = 0;

    /** @var string|null Path to the current batch file. */
    public ?string $batch_file = null;

    /** @var string|null Cursor returned by the active fetch request. */
    public ?string $cursor = null;

    /** @var int Number of file entries in the current batch. */
    public int $batch_entries = 0;

    /** @var int Successfully completed file bytes before this batch. */
    public int $file_bytes_before_batch = 0;

    /** @var int Successfully completed file bytes at this batch's saved cursor. */
    public int $file_bytes_in_batch = 0;

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->offset = $data['offset'];
        $state->next_offset = $data['next_offset'];
        $state->batch_file = $data['batch_file'];
        $state->cursor = $data['cursor'];
        $state->batch_entries = $data['batch_entries'];
        $state->file_bytes_before_batch = $data['file_bytes_before_batch'];
        $state->file_bytes_in_batch = $data['file_bytes_in_batch'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'offset' => $this->offset,
            'next_offset' => $this->next_offset,
            'batch_file' => $this->batch_file,
            'cursor' => $this->cursor,
            'batch_entries' => $this->batch_entries,
            'file_bytes_before_batch' => $this->file_bytes_before_batch,
            'file_bytes_in_batch' => $this->file_bytes_in_batch,
        ];
    }
}
