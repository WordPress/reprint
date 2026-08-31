<?php

declare(strict_types=1);

namespace Reprint\Importer;

require_once dirname(__DIR__) . '/url-rewrite/class-fast-insert-scanner.php';

use PDO;
use PDOException;
use Reprint\Importer\Database\DatabaseConnection;
use RuntimeException;
use WordPress\Reprint\Server\MySQLDumpProducer;

/** Finds spatial values in producer-shaped INSERT statements and explains why they stop. */
class SpatialStatementDiagnostics {

    private DatabaseConnection $database;
    private string $source_database_version;
    private string $target_connection_label;
    private string $target_database_version;

    /** @var array<string,array{columns: array<string,string>, primary_key: string[]}> */
    private array $table_metadata = [];

    /** @var array<int,bool> */
    private array $known_target_srids = [];

    public function __construct(
        DatabaseConnection $database,
        string $source_database_version,
        string $target_connection_label
    ) {
        $this->database = $database;
        $this->source_database_version = $source_database_version;
        $this->target_connection_label = $target_connection_label;

        $result = $database->query('SELECT VERSION() AS version');
        try {
            $version = $result->fetchColumn();
        } finally {
            $result->closeCursor();
        }
        if (!is_string($version) || $version === '') {
            throw new RuntimeException('The target database returned no version for spatial checks.');
        }
        $this->target_database_version = $version;
    }

    /**
     * Returns source-row context for a producer-shaped spatial INSERT.
     *
     * @return array|null {
     *     Spatial INSERT context, or null for another statement.
     *
     *     @type string $table Table name.
     *     @type array  $spatial_values Spatial values in statement order.
     *     @type array  $zero_byte_values Values known to be normalized placeholders.
     * }
     */
    public function inspect(string $sql): ?array
    {
        $zero_byte_rows = $this->extract_zero_byte_rows($sql);
        $sql_without_markers = preg_replace(
            '/' . preg_quote(MySQLDumpProducer::ZERO_BYTE_SPATIAL_ROW_COMMENT_PREFIX, '/') .
                '[A-Za-z0-9+\/=]+ \*\//',
            '',
            $sql
        );
        if (!is_string($sql_without_markers)) {
            throw new RuntimeException('Cannot remove zero-byte spatial row markers.');
        }
        // FastInsertScanner handles SQL literals, not function calls. This
        // exact producer expression is SQL NULL with a marker for the MariaDB
        // zero-byte placeholder, so expose the literal to the scanner.
        $sql_without_markers = str_replace(
            'NULLIF(1, 1 ' . MySQLDumpProducer::ZERO_BYTE_SPATIAL_VALUE_COMMENT . ')',
            'NULL',
            $sql_without_markers
        );
        $sql_without_leading_comments = preg_replace(
            '/\A(?:(?:\s+)|(?:--[^\r\n]*(?:\r?\n|\z))|(?:#[^\r\n]*(?:\r?\n|\z))|(?:\/\*.*?\*\/))*/s',
            '',
            $sql_without_markers
        );
        if (!is_string($sql_without_leading_comments)) {
            throw new RuntimeException('Cannot remove comments before the spatial INSERT.');
        }
        $sql_without_duplicate_update = preg_replace(
            '/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*;\s*\z/is',
            ';',
            $sql_without_leading_comments
        );
        if (!is_string($sql_without_duplicate_update)) {
            throw new RuntimeException('Cannot isolate the spatial INSERT values.');
        }

        $insert = \FastInsertScanner::scan($sql_without_duplicate_update, false, false);
        if ($insert === null || empty($insert['columns']) || empty($insert['value_entries'])) {
            return null;
        }

        $metadata = $this->get_table_metadata($insert['table']);
        $spatial_columns = [];
        foreach ($metadata['columns'] as $column => $data_type) {
            if ($this->is_spatial_type($data_type)) {
                $spatial_columns[$column] = strtoupper($data_type);
            }
        }
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Detailed CLI diagnostics, never HTML output.
        if ($spatial_columns === []) {
            if ($zero_byte_rows !== []) {
                throw new RuntimeException(
                    'The zero-byte spatial row marker for table ' .
                    $this->quote_identifier($zero_byte_rows[0]['table']) .
                    ' names spatial columns, but the target table has none.'
                );
            }
            return null;
        }

        $column_count = count($insert['columns']);
        if (count($insert['value_entries']) % $column_count !== 0) {
            throw new RuntimeException('Cannot map the spatial INSERT values to their columns.');
        }
        $row_count = intdiv(count($insert['value_entries']), $column_count);
        foreach ($zero_byte_rows as $zero_byte_row) {
            if ($zero_byte_row['table'] !== $insert['table']) {
                throw new RuntimeException(
                    'The zero-byte spatial row marker names table ' .
                    $this->quote_identifier($zero_byte_row['table']) .
                    ', but its INSERT names ' . $this->quote_identifier($insert['table']) . '.'
                );
            }
            if ($zero_byte_row['row_number'] > $row_count) {
                throw new RuntimeException(
                    'The zero-byte spatial row marker names statement row ' .
                    number_format($zero_byte_row['row_number']) . ', but the INSERT row count is ' .
                    number_format($row_count) . '.'
                );
            }
            $marker_primary_key = array_keys($zero_byte_row['primary_key']);
            if ($marker_primary_key !== $metadata['primary_key']) {
                throw new RuntimeException(
                    'The zero-byte spatial row marker names primary-key columns ' .
                    $this->format_identifier_list($marker_primary_key) .
                    ', but the target primary key is ' .
                    $this->format_identifier_list($metadata['primary_key']) . '.'
                );
            }
            foreach ($zero_byte_row['columns'] as $column) {
                if (!isset($spatial_columns[$column])) {
                    $reported_type = $metadata['columns'][$column] ?? null;
                    throw new RuntimeException(
                        'The zero-byte spatial row marker names column ' .
                        $this->quote_identifier($column) . ', but the target reports ' .
                        ( $reported_type === null ? 'no such column' : "type {$reported_type}" ) . '.'
                    );
                }
            }
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

        $spatial_values = [];
        $zero_byte_values = [];
        foreach (array_chunk($insert['value_entries'], $column_count) as $row_index => $entries) {
            $row = [];
            foreach ($entries as $entry) {
                $row[$entry['column']] = $entry;
            }
            $primary_key = [];
            foreach ($metadata['primary_key'] as $primary_key_column) {
                if (isset($row[$primary_key_column])) {
                    $primary_key[$primary_key_column] = $this->describe_sql_value(
                        $row[$primary_key_column]
                    );
                }
            }

            foreach ($spatial_columns as $column => $data_type) {
                if (!isset($row[$column])) {
                    continue;
                }
                $value = $this->describe_spatial_value(
                    $insert['table'],
                    $row_index + 1,
                    $primary_key,
                    $column,
                    $data_type,
                    $row[$column]
                );
                $value['zero_byte_placeholder'] = $this->is_marked_zero_byte_value(
                    $zero_byte_rows,
                    $value
                );
                $spatial_values[] = $value;
                if ($value['zero_byte_placeholder']) {
                    $zero_byte_values[] = $value;
                }
            }
        }

        return [
            'table' => $insert['table'],
            'spatial_values' => $spatial_values,
            'zero_byte_values' => $zero_byte_values,
        ];
    }

    /** Stops before an INSERT whose SRID cannot be interpreted safely by the target. */
    public function assert_supported(?array $inspection): void
    {
        if ($inspection === null) {
            return;
        }

        foreach ($inspection['spatial_values'] as $value) {
            if ($value['state'] !== 'value' || $value['srid'] === null || $value['srid'] === 0) {
                continue;
            }

            $target_uses_srs_definitions = $this->database_uses_srs_definitions(
                $this->target_database_version
            );
            if ($target_uses_srs_definitions && !$this->target_has_srid($value['srid'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Detailed CLI diagnostic, never HTML output.
                throw new RuntimeException($this->unknown_srid_message($value));
            }

            $source_product = $this->database_product($this->source_database_version);
            if (
                $source_product !== 'Unknown'
                && $this->database_uses_srs_definitions($this->source_database_version)
                    !== $target_uses_srs_definitions
            ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Detailed CLI diagnostic, never HTML output.
                throw new RuntimeException($this->axis_order_message($value));
            }
        }
    }

    /** Returns spatial failure details, or null when the failed row has no relevant spatial value. */
    public function describe_target_failure(
        PDOException $error,
        ?array $inspection,
        int $statement_number,
        ?int $db_sql_group_start,
        string $sql
    ): ?string {
        $zero_byte_values = $inspection['zero_byte_values'];
        $nonzero_values = array_values(array_filter(
            $inspection['spatial_values'],
            static function (array $value): bool {
                return $value['state'] === 'value' && $value['bytes'] > 0;
            }
        ));
        $zero_byte_value = $zero_byte_values[0] ?? null;
        $spatial_value = $zero_byte_value ?? ( $nonzero_values[0] ?? null );
        if ($spatial_value === null) {
            return null;
        }
        $candidate_count = $zero_byte_value !== null
            ? count($zero_byte_values)
            : count($nonzero_values);

        $target_error = $error->getMessage();
        $is_constraint_error = $zero_byte_value !== null
            && preg_match('/\b(check|constraint)\b/i', $target_error) === 1;
        $code = $is_constraint_error
            ? 'SPATIAL_NULL_CONSTRAINT'
            : 'SPATIAL_VALUE_REJECTED';
        $reason = $is_constraint_error
            ? 'The target rejected a row containing SQL NULL converted from a MariaDB zero-byte spatial placeholder.'
            : 'The target rejected a row containing a nonzero spatial value.';

        $lines = [
            "[{$code}] {$reason}",
            '',
            $this->source_line(),
            $this->target_line(),
            'Table: ' . $this->quote_identifier($spatial_value['table']),
            ( $candidate_count === 1 ? 'Row: ' : 'First candidate row: ' ) .
                $this->format_primary_key($spatial_value),
            'Column: ' . $this->quote_identifier($spatial_value['column']) .
                ' ' . $spatial_value['data_type'],
        ];
        if ($candidate_count > 1) {
            $lines[] = 'Spatial value candidates in this statement: ' . number_format($candidate_count);
        }
        if ($is_constraint_error) {
            $lines[] = 'Conversion: zero bytes -> SQL NULL';
            if (preg_match('/constraint\s+[\'`"]([^\'`"]+)[\'`"]/i', $target_error, $match)) {
                $lines[] = "Target constraint: '{$match[1]}'";
            }
        } else {
            $lines[] = 'Stored value: ' . number_format($spatial_value['bytes']) . ' bytes';
            $lines[] = 'SRID: ' . ( $spatial_value['srid'] ?? 'unreadable' );
            $lines[] = 'SHA-256: ' . $spatial_value['sha256'];
        }
        $lines[] = '';
        $lines[] = 'Target error ' . $error->getCode() . ': ' . $target_error;
        $lines[] = 'Statement in SQL group: ' . number_format($statement_number);
        if ($db_sql_group_start !== null) {
            $lines[] = 'db.sql group starts at byte: ' . number_format($db_sql_group_start);
        }
        $lines[] = 'SQL statement SHA-256: ' . hash('sha256', $sql);
        $lines[] = 'The target cursor did not advance.';
        $lines[] = '';
        if ($is_constraint_error) {
            $lines[] = 'Inspect the target with:';
            $lines[] = 'SHOW CREATE TABLE ' . $this->quote_identifier($spatial_value['table']) . ';';
            $lines[] = '';
            $lines[] = 'Change the source schema, or change the target constraint and resume db-apply.';
            $lines[] = 'Run pull --abort if db.sql must be rebuilt from the source.';
        } else {
            $lines[] = 'Reprint did not replace this nonzero value with NULL.';
            $lines[] = 'Inspect the source with:';
            $lines[] = $this->source_inspection_query($spatial_value);
            $lines[] = '';
            $lines[] = 'Correct the source value or migrate this table separately, then restart the pull.';
        }
        return implode("\n", $lines);
    }

    /** @return array<int,array{table:string,row_number:int,primary_key:array<string,string|null>,columns:string[]}> */
    private function extract_zero_byte_rows(string $sql): array
    {
        preg_match_all(
            '/' . preg_quote(MySQLDumpProducer::ZERO_BYTE_SPATIAL_ROW_COMMENT_PREFIX, '/') .
                '([A-Za-z0-9+\/=]+) \*\//',
            $sql,
            $matches
        );
        $rows = [];
        foreach ($matches[1] as $encoded_payload) {
            $json = base64_decode($encoded_payload, true);
            $payload = is_string($json) && base64_encode($json) === $encoded_payload
                ? json_decode($json, true)
                : null;
            if (!is_array($payload)) {
                throw new RuntimeException('The zero-byte spatial row marker is invalid.');
            }
            $table = $this->decode_marker_string($payload['t'] ?? null);
            $row_number = $payload['r'] ?? null;
            if (!is_int($row_number) || $row_number < 1) {
                throw new RuntimeException('The zero-byte spatial row marker has an invalid row number.');
            }
            if (!is_array($payload['c'] ?? null) || $payload['c'] === []) {
                throw new RuntimeException('The zero-byte spatial row marker has no columns.');
            }
            $columns = [];
            foreach ($payload['c'] as $encoded_column) {
                $column = $this->decode_marker_string($encoded_column);
                if (in_array($column, $columns, true)) {
                    throw new RuntimeException('The zero-byte spatial row marker repeats a column.');
                }
                $columns[] = $column;
            }
            if (!is_array($payload['k'] ?? null)) {
                throw new RuntimeException('The zero-byte spatial row marker has no primary-key list.');
            }
            $primary_key = [];
            foreach ($payload['k'] as $item) {
                if (!is_array($item) || !is_bool($item['n'] ?? null)) {
                    throw new RuntimeException('The zero-byte spatial row marker has an invalid primary key.');
                }
                $column = $this->decode_marker_string($item['c'] ?? null);
                if (array_key_exists($column, $primary_key)) {
                    throw new RuntimeException('The zero-byte spatial row marker repeats a primary-key column.');
                }
                $primary_key[$column] = $item['n']
                    ? null
                    : $this->decode_marker_string($item['v'] ?? null, true);
            }
            $rows[] = [
                'table' => $table,
                'row_number' => $row_number,
                'primary_key' => $primary_key,
                'columns' => $columns,
            ];
        }
        return $rows;
    }

    /**
     * @return array{columns: array<string,string>, primary_key: string[]}
     */
    private function get_table_metadata(string $table): array
    {
        if (isset($this->table_metadata[$table])) {
            return $this->table_metadata[$table];
        }
        $quoted_table = $this->quote_identifier($table);
        $column_result = $this->database->query("SHOW FULL COLUMNS FROM {$quoted_table}");
        try {
            $column_rows = $column_result->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $column_result->closeCursor();
        }
        $columns = [];
        foreach ($column_rows as $row) {
            if (is_string($row['Field'] ?? null) && is_string($row['Type'] ?? null)) {
                $columns[$row['Field']] = $row['Type'];
            }
        }

        $has_spatial_column = false;
        foreach ($columns as $data_type) {
            if ($this->is_spatial_type($data_type)) {
                $has_spatial_column = true;
                break;
            }
        }
        if (!$has_spatial_column) {
            $this->table_metadata[$table] = [
                'columns' => $columns,
                'primary_key' => [],
            ];
            return $this->table_metadata[$table];
        }

        $key_result = $this->database->query("SHOW KEYS FROM {$quoted_table}");
        try {
            $key_rows = $key_result->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $key_result->closeCursor();
        }
        $primary_key_by_position = [];
        foreach ($key_rows as $row) {
            if (
                ( $row['Key_name'] ?? null ) === 'PRIMARY'
                && is_numeric($row['Seq_in_index'] ?? null)
                && is_string($row['Column_name'] ?? null)
            ) {
                $primary_key_by_position[ (int) $row['Seq_in_index']] = $row['Column_name'];
            }
        }
        ksort($primary_key_by_position);

        $this->table_metadata[$table] = [
            'columns' => $columns,
            'primary_key' => array_values($primary_key_by_position),
        ];
        return $this->table_metadata[$table];
    }

    /** @return array{raw:string|null,display:string,sql:string} */
    private function describe_sql_value(array $entry): array
    {
        switch ($entry['kind']) {
            case 'null':
                return ['raw' => null, 'display' => 'NULL', 'sql' => 'IS NULL'];
            case 'empty_string':
                return ['raw' => '', 'display' => "''", 'sql' => "= ''"];
            case 'numeric':
                return [
                    'raw' => $entry['raw'],
                    'display' => $entry['raw'],
                    'sql' => '= ' . $entry['raw'],
                ];
            case 'base64':
                $decoded = base64_decode($entry['encoded_value'], true);
                if ($decoded === false) {
                    throw new RuntimeException('The spatial INSERT contains invalid base64 data.');
                }
                $json = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return [
                    'raw' => $decoded,
                    'display' => $json !== false ? $json : 'base64:' . base64_encode($decoded),
                    'sql' => "= FROM_BASE64('" . base64_encode($decoded) . "')",
                ];
        }
        throw new RuntimeException('The spatial INSERT contains an unsupported primary-key value.');
    }

    private function describe_spatial_value(
        string $table,
        int $row_number,
        array $primary_key,
        string $column,
        string $data_type,
        array $entry
    ): array {
        $state = $entry['kind'] === 'null' ? 'null' : 'value';
        $bytes = 0;
        $srid = null;
        $sha256 = hash('sha256', '');
        if ($entry['kind'] === 'base64') {
            $decoded = base64_decode($entry['encoded_value'], true);
            if ($decoded === false) {
                throw new RuntimeException('The spatial INSERT contains invalid base64 data.');
            }
            $bytes = strlen($decoded);
            $sha256 = hash('sha256', $decoded);
            if ($bytes >= 4) {
                // MySQL and MariaDB internal geometry bytes start with a little-endian 32-bit SRID.
                $unpacked = unpack('Vsrid', substr($decoded, 0, 4));
                $srid = is_array($unpacked) ? (int) $unpacked['srid'] : null;
            }
        } elseif ($entry['kind'] === 'empty_string') {
            $state = 'value';
        } elseif ($entry['kind'] !== 'null') {
            throw new RuntimeException('The spatial INSERT contains an unsupported value expression.');
        }

        return [
            'table' => $table,
            'row_number' => $row_number,
            'primary_key' => $primary_key,
            'column' => $column,
            'data_type' => $data_type,
            'state' => $state,
            'bytes' => $bytes,
            'srid' => $srid,
            'sha256' => $sha256,
        ];
    }

    private function is_marked_zero_byte_value(array $zero_byte_rows, array $value): bool
    {
        foreach ($zero_byte_rows as $row) {
            if ($row['table'] !== $value['table'] || !in_array($value['column'], $row['columns'], true)) {
                continue;
            }
            if ($row['row_number'] !== $value['row_number']) {
                continue;
            }
            $matches = true;
            foreach ($row['primary_key'] as $column => $raw_value) {
                if (( $value['primary_key'][$column]['raw'] ?? null ) !== $raw_value) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    private function target_has_srid(int $srid): bool
    {
        if (array_key_exists($srid, $this->known_target_srids)) {
            return $this->known_target_srids[$srid];
        }
        $result = $this->database->query(
            'SELECT SRS_ID FROM INFORMATION_SCHEMA.ST_SPATIAL_REFERENCE_SYSTEMS ' .
            'WHERE SRS_ID = ?',
            [$srid]
        );
        try {
            $exists = $result->fetchColumn() !== false;
        } finally {
            $result->closeCursor();
        }
        $this->known_target_srids[$srid] = $exists;
        return $exists;
    }

    private function unknown_srid_message(array $value): string
    {
        return implode("\n", [
            '[SPATIAL_SRID_UNKNOWN] The target does not define this spatial reference system.',
            '',
            $this->source_line(),
            $this->target_line(),
            'Table: ' . $this->quote_identifier($value['table']),
            'Row: ' . $this->format_primary_key($value),
            'Column: ' . $this->quote_identifier($value['column']) . ' ' . $value['data_type'],
            'SRID: ' . $value['srid'],
            'Stored value: ' . number_format($value['bytes']) . ' bytes',
            'SHA-256: ' . $value['sha256'],
            '',
            'The row was not inserted. The target cursor did not advance.',
            '',
            'Inspect the target with:',
            'SELECT SRS_ID, SRS_NAME',
            'FROM INFORMATION_SCHEMA.ST_SPATIAL_REFERENCE_SYSTEMS',
            'WHERE SRS_ID = ' . $value['srid'] . ';',
            '',
            'Create a matching target SRS, transform the source value to a supported SRID,',
            'or migrate this table separately.',
        ]);
    }

    private function axis_order_message(array $value): string
    {
        return implode("\n", [
            '[SPATIAL_AXIS_ORDER_UNSAFE] Reprint cannot safely move this nonzero SRID between different database engines.',
            '',
            $this->source_line(),
            $this->target_line(),
            'Table: ' . $this->quote_identifier($value['table']),
            'Row: ' . $this->format_primary_key($value),
            'Column: ' . $this->quote_identifier($value['column']) . ' ' . $value['data_type'],
            'SRID: ' . $value['srid'],
            'Stored value: ' . number_format($value['bytes']) . ' bytes',
            'SHA-256: ' . $value['sha256'],
            '',
            'These servers do not apply spatial reference system definitions in the same way.',
            'They can assign different meanings to the first and second coordinates for this SRID.',
            'Reprint does not currently transform coordinates.',
            '',
            'The row was not inserted. The target cursor did not advance.',
            '',
            'Inspect the source with:',
            $this->source_inspection_query($value),
            '',
            'Convert the source data to SRID 0, transform it for the target, or migrate this table separately.',
        ]);
    }

    private function source_line(): string
    {
        return 'Source: ' . $this->database_product($this->source_database_version) .
            ' ' . $this->source_database_version;
    }

    private function target_line(): string
    {
        return 'Target: ' . $this->database_product($this->target_database_version) .
            ' ' . $this->target_database_version . ' (' . $this->target_connection_label . ')';
    }

    private function format_primary_key(array $value): string
    {
        if ($value['primary_key'] === []) {
            return 'statement row ' . number_format($value['row_number']) . ' (table has no primary key)';
        }
        $parts = [];
        foreach ($value['primary_key'] as $column => $primary_key_value) {
            $parts[] = $this->quote_identifier($column) . ' = ' . $primary_key_value['display'];
        }
        return implode(', ', $parts);
    }

    private function source_inspection_query(array $value): string
    {
        $query = 'SELECT OCTET_LENGTH(' . $this->quote_identifier($value['column']) .
            '), ST_SRID(' . $this->quote_identifier($value['column']) . ")\n" .
            'FROM ' . $this->quote_identifier($value['table']);
        if ($value['primary_key'] !== []) {
            $predicates = [];
            foreach ($value['primary_key'] as $column => $primary_key_value) {
                $predicates[] = $this->quote_identifier($column) . ' ' . $primary_key_value['sql'];
            }
            $query .= "\nWHERE " . implode(' AND ', $predicates);
        }
        return $query . ';';
    }

    private function decode_marker_string($encoded, bool $allow_empty = false): string
    {
        if (!is_string($encoded)) {
            throw new RuntimeException('The zero-byte spatial row marker is invalid.');
        }
        $decoded = base64_decode($encoded, true);
        if (
            $decoded === false
            || base64_encode($decoded) !== $encoded
            || ( !$allow_empty && $decoded === '' )
        ) {
            throw new RuntimeException('The zero-byte spatial row marker contains invalid base64.');
        }
        return $decoded;
    }

    private function database_product(string $version): string
    {
        if ($version === '') {
            return 'Unknown';
        }
        return stripos($version, 'MariaDB') !== false ? 'MariaDB' : 'MySQL';
    }

    /** MySQL 8 and later use registered SRS definitions when interpreting coordinates. */
    private function database_uses_srs_definitions(string $version): bool
    {
        if ($this->database_product($version) !== 'MySQL') {
            return false;
        }
        if (preg_match('/^(\d+)\./', $version, $match) !== 1) {
            return false;
        }
        return (int) $match[1] >= 8;
    }

    private function is_spatial_type(string $data_type): bool
    {
        $base_type = strtoupper( (string) preg_replace('/[\s(].*$/', '', $data_type));
        return in_array($base_type, [
            'GEOMETRY',
            'POINT',
            'LINESTRING',
            'POLYGON',
            'MULTIPOINT',
            'MULTILINESTRING',
            'MULTIPOLYGON',
            'GEOMCOLLECTION',
            'GEOMETRYCOLLECTION',
        ], true);
    }

    /** @param string[] $identifiers */
    private function format_identifier_list(array $identifiers): string
    {
        if ($identifiers === []) {
            return '(none)';
        }
        return implode(', ', array_map([$this, 'quote_identifier'], $identifiers));
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
