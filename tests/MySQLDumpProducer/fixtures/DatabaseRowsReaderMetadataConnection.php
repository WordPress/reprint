<?php

require_once __DIR__ . '/DatabaseRowsReaderMetadataStatement.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
final class DatabaseRowsReaderMetadataConnection {
    /** @var string[] */
    private $tables;

    /** @var array[] */
    private $index_rows;

    /** @var string[] */
    public $queries = [];

    /**
     * @param string[]   $tables     Tables returned by SHOW FULL TABLES.
     * @param array[]|null $index_rows Rows returned by SHOW INDEX.
     */
    public function __construct(array $tables, $index_rows = null)
    {
        $this->tables = $tables;
        $this->index_rows = $index_rows ?? [
            ['Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Seq_in_index' => 1],
        ];
    }

    public function query(string $query): DatabaseRowsReaderMetadataStatement
    {
        $this->queries[] = $query;
        if ($query === 'SHOW FULL TABLES') {
            return new DatabaseRowsReaderMetadataStatement(array_map(static function ($table) {
                return ['table' => $table, 'type' => 'BASE TABLE'];
            }, $this->tables));
        }
        if (strpos($query, 'SHOW INDEX FROM ') === 0) {
            return new DatabaseRowsReaderMetadataStatement($this->index_rows);
        }
        if (strpos($query, 'SHOW FULL COLUMNS FROM ') === 0) {
            return new DatabaseRowsReaderMetadataStatement([
                ['Field' => 'id', 'Type' => 'int(11)'],
            ]);
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        throw new RuntimeException("Unexpected query: {$query}");
    }
}
