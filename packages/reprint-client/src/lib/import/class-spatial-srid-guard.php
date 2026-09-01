<?php

declare(strict_types=1);

namespace Reprint\Importer;

use Reprint\Importer\Database\DatabaseConnection;
use RuntimeException;
use WordPress\Reprint\Server\MySQLDumpProducer;

/** Stops nonzero SRIDs when the source and target interpret them differently. */
class SpatialSridGuard {

    private DatabaseConnection $database;
    private string $source_database_version;
    private ?bool $source_uses_srs_definitions;
    private string $target_connection_label;
    private string $target_database_version;
    private ?bool $target_uses_srs_definitions = null;

    public function __construct(
        DatabaseConnection $database,
        string $source_database_version,
        ?bool $source_uses_srs_definitions,
        string $target_connection_label
    ) {
        $this->database = $database;
        $this->source_database_version = $source_database_version;
        $this->source_uses_srs_definitions = $source_uses_srs_definitions;
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

    /** Stops before a marked INSERT when its nonzero SRID may change meaning. */
    public function assert_statement_supported(string $sql): void
    {
        $context = $this->read_context($sql);
        if ($context === null) {
            return;
        }
        if ($this->source_uses_srs_definitions === null) {
            throw new RuntimeException(
                'The source preflight did not report spatial reference rules. Run preflight and pull again.'
            );
        }
        if ($this->source_uses_srs_definitions === $this->target_uses_srs_definitions()) {
            return;
        }

        $message = implode("\n", [
            '[SPATIAL_AXIS_ORDER_UNSAFE] Reprint cannot safely move this nonzero SRID between these databases.',
            '',
            'Source: ' . $this->database_product($this->source_database_version) .
                ' ' . $this->source_database_version,
            'Target: ' . $this->database_product($this->target_database_version) .
                ' ' . $this->target_database_version . ' (' . $this->target_connection_label . ')',
            $context['row_details'],
            '',
            'One server uses registered spatial reference definitions and the other does not.',
            'They may assign different meanings to the first and second coordinates.',
            'Reprint does not currently transform coordinates.',
            '',
            'The INSERT batch was not executed.',
            'Convert the source data to SRID 0, transform it for the target, or migrate this table separately.',
        ]);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Detailed CLI diagnostic, never HTML output.
        throw new RuntimeException($message);
    }

    /** Returns marked source-row context, or null for an ordinary statement. */
    private function read_context(string $sql): ?array
    {
        $marker_prefix = MySQLDumpProducer::NONZERO_SRID_COMMENT_PREFIX;
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
            throw new RuntimeException('The nonzero SRID marker has no context version.');
        }
        $version = substr($sql, $version_start, $version_end - $version_start);
        if ($version !== MySQLDumpProducer::NONZERO_SRID_CONTEXT_VERSION) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Detailed CLI diagnostic, never HTML output.
            throw new RuntimeException('The nonzero SRID marker uses unsupported context version ' . $version . '.');
        }

        $context_start = $version_end + 1;
        $context_end = strpos($sql, ' */', $context_start);
        if ($context_end === false) {
            throw new RuntimeException('The nonzero SRID marker has no closing comment token.');
        }
        $context = json_decode(substr($sql, $context_start, $context_end - $context_start), true);
        if (
            !is_array($context) ||
            count($context) !== 3 ||
            !array_key_exists('table', $context) ||
            !array_key_exists('primary_key', $context) ||
            !array_key_exists('spatial_columns', $context)
        ) {
            throw new RuntimeException('The nonzero SRID marker has an invalid object shape.');
        }
        if (!is_string($context['table']) || $context['table'] === '') {
            throw new RuntimeException('The nonzero SRID marker has an invalid table.');
        }
        if (!is_array($context['primary_key'])) {
            throw new RuntimeException('The nonzero SRID marker has an invalid primary key list.');
        }
        $primary_key = [];
        foreach ($context['primary_key'] as $column) {
            if (
                !is_array($column) ||
                count($column) !== 2 ||
                !array_key_exists('column', $column) ||
                !array_key_exists('display_value', $column) ||
                !is_string($column['column']) ||
                $column['column'] === '' ||
                !is_string($column['display_value']) ||
                $column['display_value'] === ''
            ) {
                throw new RuntimeException('The nonzero SRID marker has invalid primary key fields.');
            }
            $primary_key[] = $this->quote_identifier($column['column']) .
                ' = ' . $column['display_value'];
        }
        if (
            !is_array($context['spatial_columns']) ||
            $context['spatial_columns'] === []
        ) {
            throw new RuntimeException('The nonzero SRID marker has an invalid spatial column list.');
        }
        $row_details = [
            'Table: ' . $this->quote_identifier($context['table']),
            'Row: ' . ( $primary_key === []
                ? 'statement row 1 (table has no primary key)'
                : implode(', ', $primary_key) ),
        ];
        foreach ($context['spatial_columns'] as $column) {
            if (
                !is_array($column) ||
                count($column) !== 2 ||
                !array_key_exists('column', $column) ||
                !array_key_exists('srid', $column) ||
                !is_string($column['column']) ||
                $column['column'] === '' ||
                !is_int($column['srid']) ||
                $column['srid'] <= 0
            ) {
                throw new RuntimeException('The nonzero SRID marker has invalid spatial column fields.');
            }
            $row_details[] = 'Column: ' . $this->quote_identifier($column['column']) .
                ', SRID ' . $column['srid'];
        }
        return [
            'row_details' => implode("\n", $row_details),
        ];
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
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

    private function database_product(string $version): string
    {
        return stripos($version, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
    }
}
