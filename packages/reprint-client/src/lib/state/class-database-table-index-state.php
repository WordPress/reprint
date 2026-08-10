<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class DatabaseTableIndexState {

    /** @var string|null Path to the db table index file. */
    public ?string $file = null;

    /** @var int Number of tables indexed. */
    public int $tables = 0;

    /** @var int Estimated number of rows across indexed tables. */
    public int $rows_estimated = 0;

    /** @var int Bytes represented by the index. */
    public int $bytes = 0;

    /** @var string|null Timestamp of the latest index update. */
    public ?string $updated_at = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->file = $data['file'];
        $state->tables = $data['tables'];
        $state->rows_estimated = $data['rows_estimated'];
        $state->bytes = $data['bytes'];
        $state->updated_at = $data['updated_at'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'file' => $this->file,
            'tables' => $this->tables,
            'rows_estimated' => $this->rows_estimated,
            'bytes' => $this->bytes,
            'updated_at' => $this->updated_at,
        ];
    }
}
