<?php

require_once __DIR__ . '/DatabaseRowsReaderMetadataStatement.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
final class DatabaseRowsReaderMetadataConnection {
    /** @var string[] */
    private $tables;

    /** @var string[] */
    public $queries = [];

    /** @param string[] $tables */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
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
            return new DatabaseRowsReaderMetadataStatement([
                ['Column_name' => 'id', 'Seq_in_index' => 1],
            ]);
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
