<?php

declare(strict_types=1);

namespace Reprint\Importer;

use PDOException;
use Reprint\Importer\Database\DatabaseConnection;
use RuntimeException;
use WordPress\Reprint\Server\MySQLDumpProducer;

/** Checks versioned spatial statement context and explains target failures. */
class SpatialStatementDiagnostics {

    private DatabaseConnection $database;
    private string $source_database_version;
    private string $target_connection_label;
    private string $target_database_version;
    private ?bool $target_uses_srs_definitions = null;

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
     * Reads source-row context from a versioned Reprint comment.
     *
     * The producer hashes the context together with the complete INSERT. The
     * importer can therefore use the source row details without parsing SQL.
     *
     * @return array|null {
     *     Spatial statement context, or null for an unmarked statement.
     *
     *     @type string $table                       Table name.
     *     @type array  $primary_key                 Source primary-key values.
     *     @type bool   $source_uses_srs_definitions Whether the source applies registered SRS definitions.
     *     @type array  $spatial_values              Marked zero-byte or nonzero-SRID values.
     * }
     */
    public function inspect(string $sql): ?array
    {
        $marker_prefix = MySQLDumpProducer::SPATIAL_STATEMENT_COMMENT_PREFIX;
        $marker_position = strpos($sql, $marker_prefix);
        if ($marker_position === false) {
            return null;
        }
        if ($marker_position > 0 && substr($sql, $marker_position - 1, 1) !== "\n") {
            return null;
        }
        $version_start = $marker_position + strlen($marker_prefix);
        $version_end = strpos($sql, ' ', $version_start);
        if ($version_end === false) {
            throw new RuntimeException('The spatial statement marker has no context version.');
        }
        $version = substr($sql, $version_start, $version_end - $version_start);
        if ($version !== MySQLDumpProducer::SPATIAL_STATEMENT_CONTEXT_VERSION) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Detailed CLI diagnostic, never HTML output.
            throw new RuntimeException('The spatial statement marker uses unsupported context version ' . $version . '.');
        }
        $payload_start = $version_end + 1;
        $payload_end = strpos($sql, ' */', $payload_start);
        if ($payload_end === false) {
            throw new RuntimeException('The spatial statement marker has no closing comment token.');
        }
        $marker_body = substr($sql, $payload_start, $payload_end - $payload_start);
        $hash_separator = strrpos($marker_body, ' ');
        if ($hash_separator === false) {
            throw new RuntimeException('The spatial statement marker has no SQL statement hash.');
        }
        $encoded_payload = substr($marker_body, 0, $hash_separator);
        $reported_hash = substr($marker_body, $hash_separator + 1);
        $json = base64_decode($encoded_payload, true);
        if (!is_string($json) || base64_encode($json) !== $encoded_payload) {
            throw new RuntimeException('The spatial statement marker contains invalid base64.');
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || !$this->has_exact_keys($payload, ['t', 'k', 'd', 'v'])) {
            throw new RuntimeException('The spatial statement marker has an invalid object shape.');
        }

        $statement_start = $payload_end + 3;
        if (substr($sql, $statement_start, 2) === "\r\n") {
            $statement_start += 2;
        } elseif (substr($sql, $statement_start, 1) === "\n") {
            ++$statement_start;
        } else {
            throw new RuntimeException('The spatial statement marker has no following SQL line.');
        }
        $statement = substr($sql, $statement_start);
        if (
            !$this->is_sha256($reported_hash)
            || !hash_equals($reported_hash, hash('sha256', $json . "\n" . $statement))
        ) {
            throw new RuntimeException('The spatial statement marker does not match its SQL statement.');
        }
        if (!is_bool($payload['d'])) {
            throw new RuntimeException('The spatial statement marker has an invalid source SRS mode.');
        }

        $table = $this->decode_marker_string($payload['t']);
        $primary_key = $this->decode_primary_key($payload['k']);
        $spatial_values = $this->decode_spatial_values($payload['v']);
        return [
            'table' => $table,
            'primary_key' => $primary_key,
            'source_uses_srs_definitions' => $payload['d'],
            'spatial_values' => $spatial_values,
        ];
    }

    /** Stops before an INSERT whose SRID cannot be interpreted safely by the target. */
    public function assert_supported(?array $inspection): void
    {
        if ($inspection === null) {
            return;
        }
        foreach ($inspection['spatial_values'] as $value) {
            if ($value['zero_byte_placeholder']) {
                continue;
            }
            $target_uses_srs_definitions = $this->target_uses_srs_definitions();
            if ($target_uses_srs_definitions && !$this->target_has_srid($value['srid'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Detailed CLI diagnostic, never HTML output.
                throw new RuntimeException($this->unknown_srid_message($inspection, $value));
            }
            if ($inspection['source_uses_srs_definitions'] !== $target_uses_srs_definitions) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Detailed CLI diagnostic, never HTML output.
                throw new RuntimeException($this->axis_order_message($inspection, $value));
            }
        }
    }

    /** Returns a cautious report for a rejected marked row. */
    public function describe_target_failure(
        PDOException $error,
        ?array $inspection,
        int $statement_number,
        ?int $db_sql_group_start,
        string $sql
    ): ?string {
        if ($inspection === null) {
            return null;
        }
        $first_value = $inspection['spatial_values'][0];
        $lines = [
            '[SPATIAL_ROW_REJECTED] The target rejected a row which contains marked spatial values.',
            '',
            $this->source_line(),
            $this->target_line(),
            'Table: ' . $this->quote_identifier($inspection['table']),
            'Row: ' . $this->format_primary_key($inspection['primary_key']),
        ];
        foreach ($inspection['spatial_values'] as $value) {
            $detail = $value['zero_byte_placeholder']
                ? 'zero bytes converted to SQL NULL'
                : 'SRID ' . $value['srid'] . ', ' . number_format($value['bytes']) .
                    ' bytes, SHA-256 ' . $value['sha256'];
            $lines[] = 'Column candidate: ' . $this->quote_identifier($value['column']) .
                ' ' . $value['data_type'] . ' (' . $detail . ')';
        }
        $lines[] = '';
        $lines[] = 'Target error ' . $this->target_error_code($error) . ': ' . $error->getMessage();
        $lines[] = 'Statement in SQL group: ' . number_format($statement_number);
        if ($db_sql_group_start !== null) {
            $lines[] = 'db.sql group starts at byte: ' . number_format($db_sql_group_start);
        }
        $lines[] = 'SQL statement SHA-256: ' . hash('sha256', $sql);
        $lines[] = 'The target cursor did not advance.';
        $lines[] = 'The target did not report which value caused the failure.';
        $lines[] = '';
        $lines[] = 'Inspect the source row with:';
        $lines[] = $this->source_inspection_query($inspection, $first_value);
        $lines[] = '';
        $lines[] = 'Inspect the target schema with:';
        $lines[] = 'SHOW CREATE TABLE ' . $this->quote_identifier($inspection['table']) . ';';
        $lines[] = '';
        $lines[] = 'Correct the source row or target schema, then resume db-apply.';
        $lines[] = 'Run pull --abort if db.sql must be rebuilt from the source.';
        return implode("\n", $lines);
    }

    /** @return array<string,array{display:string,sql:string}> */
    private function decode_primary_key($encoded_primary_key): array
    {
        if (!is_array($encoded_primary_key)) {
            throw new RuntimeException('The spatial statement marker has no primary-key list.');
        }
        $primary_key = [];
        foreach ($encoded_primary_key as $item) {
            if (!is_array($item) || !is_bool($item['n'] ?? null)) {
                throw new RuntimeException('The spatial statement marker has an invalid primary key.');
            }
            $expected_keys = $item['n'] ? ['c', 't', 'n'] : ['c', 't', 'n', 'v'];
            if (!$this->has_exact_keys($item, $expected_keys)) {
                throw new RuntimeException('The spatial statement marker has an invalid primary-key shape.');
            }
            $column = $this->decode_marker_string($item['c']);
            $data_type = $this->decode_marker_string($item['t']);
            if (array_key_exists($column, $primary_key)) {
                throw new RuntimeException('The spatial statement marker repeats a primary-key column.');
            }
            if ($item['n']) {
                $primary_key[$column] = ['display' => 'NULL', 'sql' => 'IS NULL'];
                continue;
            }
            $raw_value = $this->decode_marker_string($item['v'], true);
            if ($this->is_numeric_type($data_type) && is_numeric($raw_value)) {
                $primary_key[$column] = [
                    'display' => $raw_value,
                    'sql' => '= ' . $raw_value,
                ];
                continue;
            }
            $json = json_encode($raw_value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $primary_key[$column] = [
                'display' => $json !== false ? $json : 'base64:' . base64_encode($raw_value),
                'sql' => "= FROM_BASE64('" . base64_encode($raw_value) . "')",
            ];
        }
        return $primary_key;
    }

    /** @return array<int,array<string,mixed>> */
    private function decode_spatial_values($encoded_values): array
    {
        if (!is_array($encoded_values) || $encoded_values === []) {
            throw new RuntimeException('The spatial statement marker has no spatial values.');
        }
        $values = [];
        $seen_columns = [];
        foreach ($encoded_values as $encoded_value) {
            if (
                !is_array($encoded_value)
                || !$this->has_exact_keys($encoded_value, ['c', 't', 'z', 's', 'b', 'h'])
            ) {
                throw new RuntimeException('The spatial statement marker has an invalid spatial value shape.');
            }
            $column = $this->decode_marker_string($encoded_value['c']);
            $data_type = $this->decode_marker_string($encoded_value['t']);
            if (isset($seen_columns[$column])) {
                throw new RuntimeException('The spatial statement marker repeats a spatial column.');
            }
            if (!$this->is_spatial_type($data_type)) {
                throw new RuntimeException('The spatial statement marker reports a non-spatial column type.');
            }
            $seen_columns[$column] = true;
            $zero_byte_placeholder = $encoded_value['z'] ?? null;
            $srid = $encoded_value['s'] ?? null;
            $bytes = $encoded_value['b'] ?? null;
            $sha256 = $encoded_value['h'] ?? null;
            if (!is_bool($zero_byte_placeholder) || !is_int($bytes) || !$this->is_sha256($sha256)) {
                throw new RuntimeException('The spatial statement marker has invalid spatial value details.');
            }
            if ($zero_byte_placeholder) {
                if ($srid !== null || $bytes !== 0 || $sha256 !== hash('sha256', '')) {
                    throw new RuntimeException('The spatial statement marker has invalid zero-byte details.');
                }
            } elseif (!is_int($srid) || $srid <= 0 || $bytes < 4) {
                throw new RuntimeException('The spatial statement marker has invalid nonzero-SRID details.');
            }
            $values[] = [
                'column' => $column,
                'data_type' => strtoupper($this->base_data_type($data_type)),
                'zero_byte_placeholder' => $zero_byte_placeholder,
                'srid' => $srid,
                'bytes' => $bytes,
                'sha256' => $sha256,
            ];
        }
        return $values;
    }

    private function target_uses_srs_definitions(): bool
    {
        if ($this->target_uses_srs_definitions !== null) {
            return $this->target_uses_srs_definitions;
        }
        $result = $this->database->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES " .
            "WHERE TABLE_SCHEMA = 'information_schema' " .
            "AND TABLE_NAME = 'ST_SPATIAL_REFERENCE_SYSTEMS'"
        );
        try {
            $count = $result->fetchColumn();
        } finally {
            $result->closeCursor();
        }
        if (!is_numeric($count)) {
            throw new RuntimeException('The target database returned no spatial reference capability.');
        }
        $this->target_uses_srs_definitions = (int) $count > 0;
        return $this->target_uses_srs_definitions;
    }

    private function target_has_srid(int $srid): bool
    {
        if (array_key_exists($srid, $this->known_target_srids)) {
            return $this->known_target_srids[$srid];
        }
        $result = $this->database->query(
            'SELECT SRS_ID FROM INFORMATION_SCHEMA.ST_SPATIAL_REFERENCE_SYSTEMS WHERE SRS_ID = ?',
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

    private function unknown_srid_message(array $inspection, array $value): string
    {
        return implode("\n", [
            '[SPATIAL_SRID_UNKNOWN] The target does not define this spatial reference system.',
            '',
            $this->source_line(),
            $this->target_line(),
            'Table: ' . $this->quote_identifier($inspection['table']),
            'Row: ' . $this->format_primary_key($inspection['primary_key']),
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

    private function axis_order_message(array $inspection, array $value): string
    {
        return implode("\n", [
            '[SPATIAL_AXIS_ORDER_UNSAFE] Reprint cannot safely move this nonzero SRID between these databases.',
            '',
            $this->source_line(),
            $this->target_line(),
            'Table: ' . $this->quote_identifier($inspection['table']),
            'Row: ' . $this->format_primary_key($inspection['primary_key']),
            'Column: ' . $this->quote_identifier($value['column']) . ' ' . $value['data_type'],
            'SRID: ' . $value['srid'],
            'Stored value: ' . number_format($value['bytes']) . ' bytes',
            'SHA-256: ' . $value['sha256'],
            '',
            'One server uses registered SRS definitions and the other treats the SRID as an integer.',
            'They can assign different meanings to the first and second coordinates.',
            'Reprint does not currently transform coordinates.',
            '',
            'The row was not inserted. The target cursor did not advance.',
            '',
            'Inspect the source with:',
            $this->source_inspection_query($inspection, $value),
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

    private function format_primary_key(array $primary_key): string
    {
        if ($primary_key === []) {
            return 'statement row 1 (table has no primary key)';
        }
        $parts = [];
        foreach ($primary_key as $column => $value) {
            $parts[] = $this->quote_identifier($column) . ' = ' . $value['display'];
        }
        return implode(', ', $parts);
    }

    private function source_inspection_query(array $inspection, array $value): string
    {
        $query = 'SELECT OCTET_LENGTH(' . $this->quote_identifier($value['column']) .
            '), ST_SRID(' . $this->quote_identifier($value['column']) . ")\n" .
            'FROM ' . $this->quote_identifier($inspection['table']);
        if ($inspection['primary_key'] !== []) {
            $predicates = [];
            foreach ($inspection['primary_key'] as $column => $primary_key_value) {
                $predicates[] = $this->quote_identifier($column) . ' ' . $primary_key_value['sql'];
            }
            $query .= "\nWHERE " . implode(' AND ', $predicates);
        }
        return $query . ';';
    }

    private function decode_marker_string($encoded, bool $allow_empty = false): string
    {
        if (!is_string($encoded)) {
            throw new RuntimeException('The spatial statement marker is invalid.');
        }
        $decoded = base64_decode($encoded, true);
        if (
            $decoded === false
            || base64_encode($decoded) !== $encoded
            || ( !$allow_empty && $decoded === '' )
        ) {
            throw new RuntimeException('The spatial statement marker contains invalid base64.');
        }
        return $decoded;
    }

    private function target_error_code(PDOException $error): string
    {
        $error_info = $error->errorInfo ?? null;
        if (is_array($error_info) && is_numeric($error_info[1] ?? null)) {
            return (string) $error_info[1];
        }
        return (string) $error->getCode();
    }

    private function is_sha256($value): bool
    {
        return is_string($value)
            && strlen($value) === 64
            && ctype_xdigit($value)
            && strtolower($value) === $value;
    }

    /** Returns whether an object has exactly the expected keys, in any order. */
    private function has_exact_keys(array $value, array $expected_keys): bool
    {
        return count($value) === count($expected_keys)
            && array_diff_key($value, array_fill_keys($expected_keys, true)) === [];
    }

    private function database_product(string $version): string
    {
        if ($version === '') {
            return 'Unknown';
        }
        return stripos($version, 'MariaDB') !== false ? 'MariaDB' : 'MySQL';
    }

    private function is_spatial_type(string $data_type): bool
    {
        return in_array(strtoupper($this->base_data_type($data_type)), [
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

    private function is_numeric_type(string $data_type): bool
    {
        return in_array(strtoupper($this->base_data_type($data_type)), [
            'BIT',
            'TINYINT',
            'SMALLINT',
            'MEDIUMINT',
            'INT',
            'INTEGER',
            'BIGINT',
            'DECIMAL',
            'DEC',
            'NUMERIC',
            'FIXED',
            'FLOAT',
            'DOUBLE',
            'REAL',
            'BOOL',
            'BOOLEAN',
            'SERIAL',
        ], true);
    }

    private function base_data_type(string $data_type): string
    {
        $length = strcspn($data_type, " (\t\r\n");
        return substr($data_type, 0, $length);
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
