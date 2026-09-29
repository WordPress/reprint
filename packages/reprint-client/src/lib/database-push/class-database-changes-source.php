<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Local CLI errors, never HTML.

use WordPress\Reprint\Server\DatabasePush;

require_once __DIR__ . '/../url-rewrite/load.php';

/**
 * Reads a user-selected db-diff file; never rescans or changes the local database.
 *
 * Review and upload read the same open file twice, one bounded row at a time.
 * The receiver verifies the review hash before commit, so even an editor which
 * ignores our shared lock cannot turn a confirmed review into a different push.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Client workflow, not a WordPress plugin API.
final class DatabaseChangesSource {
    /** @var resource|null */
    private $input;
    /** @var array<string,array> */
    private $tables;
    /** @var SqlStatementRewriter */
    private $rewriter;

    /**
     * @param string $changes_file Selected JSONL records from db-diff.
     * @param string $baseline_directory Local baseline whose column layout defines the value encoding.
     * @param array<string,string> $url_mapping Local URLs mapped to hosted URLs, for both before and after values.
     * @param string $table_prefix WordPress prefix used by the existing URL rewriter.
     */
    public function __construct(string $changes_file, string $baseline_directory, array $url_mapping, string $table_prefix) {
        $manifest = json_decode(file_get_contents($baseline_directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->tables = $manifest['tables'];
        $this->rewriter = new SqlStatementRewriter(new StructuredDataUrlRewriter($url_mapping), $table_prefix);
        $input = fopen($changes_file, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot open selected database changes file ' . $changes_file . '.');
        }
        if (!flock($input, LOCK_SH | LOCK_NB)) {
            fclose($input);
            throw new RuntimeException('Another process is writing selected database changes file ' . $changes_file . '.');
        }
        $this->input = $input;
    }

    /**
     * @return array {
     *     Review of the exact rewritten records which would be sent.
     *     @type string $type database_changes_review.
     *     @type string $review SHA-256 token required for --commit.
     *     @type array $tables Table names mapped to insert, update, and delete counts.
     * }
     */
    public function review(): array {
        $hash = hash_init('sha256');
        $tables = [];
        foreach ($this->records() as $line) {
            hash_update($hash, $line);
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($record['type'] === 'database_change') {
                if (!isset($tables[$record['table']])) {
                    $tables[$record['table']] = ['insert' => 0, 'update' => 0, 'delete' => 0];
                }
                ++$tables[$record['table']][$record['action']];
            }
        }
        if ($tables === []) {
            throw new RuntimeException('The selected file contains no database changes.');
        }
        return ['type' => 'database_changes_review', 'review' => hash_final($hash), 'tables' => $tables];
    }

    /** @return Generator<int,string> Table metadata, selected changes, then end; each encoded record is at most 2 MiB. */
    public function records(): Generator {
        if (!is_resource($this->input) || !rewind($this->input)) {
            throw new RuntimeException('Cannot rewind the selected database changes file.');
        }
        $current_table = null;
        $report_seen = false;
        while (!feof($this->input)) {
            $line = fgets($this->input, DatabasePush::MAX_RECORD_BYTES + 2);
            if ($line === false && feof($this->input)) {
                break;
            }
            if ($line === false || strlen($line) > DatabasePush::MAX_RECORD_BYTES || substr($line, -1) !== "\n") {
                throw new RuntimeException('Every selected change must be a complete JSONL record of at most 2 MiB, ending with a newline.');
            }
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($report_seen) {
                throw new RuntimeException('The db-diff command report must be the last record in the selected file.');
            }
            if (( $record['type'] ?? null ) === 'reprint_report' && ( $record['command'] ?? null ) === 'db-diff' && ( $record['status'] ?? null ) === 'complete' && ( $record['exit_code'] ?? null ) === 0) {
                $report_seen = true;
                continue;
            }
            if (( $record['type'] ?? null ) !== 'database_change' || !isset($this->tables[$record['table'] ?? ''])
                || !in_array($record['action'] ?? null, ['insert', 'update', 'delete'], true)) {
                throw new RuntimeException('Select database_change records from a successful db-diff for this baseline.');
            }
            $table = $record['table'];
            $schema = $this->tables[$table];
            if ($table !== $current_table) {
                yield $this->encode_record(['type' => 'database_table', 'table' => $table, 'schema' => $schema]);
                $current_table = $table;
            }
            foreach (['key', 'before', 'after'] as $field) {
                if (!array_key_exists($field, $record) || ( $record[$field] !== null && !is_array($record[$field]) )) {
                    throw new RuntimeException('Selected change requires a key map and before/after maps or null.');
                }
                foreach ($record[$field] ?? [] as $column => $encoded) {
                    if (!isset($schema['columns'][$column]) || ( $encoded !== null && !is_string($encoded) )) {
                        throw new RuntimeException('Selected change has an unknown column or invalid value in ' . $table . '.' . $column . '.');
                    }
                    if ($encoded === null) {
                        continue;
                    }
                    $value = base64_decode($encoded, true);
                    if ($value === false || base64_encode($value) !== $encoded) {
                        throw new RuntimeException('Selected change requires canonical base64 in ' . $table . '.' . $column . '.');
                    }
                    $type = base64_decode($schema['columns'][$column]['Type']);
                    // Binary and spatial bytes must never enter the URL parser.
                    if (!preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext|json|enum|set)\b/i', $type)) {
                        continue;
                    }
                    $rewritten = $this->rewriter->rewrite_value($value, $table, $column);
                    if ($rewritten !== $value && in_array($column, $schema['primary_key'], true)) {
                        throw new RuntimeException('URL rewriting would change a primary key in ' . $table . '.' . $column . '.');
                    }
                    $record[$field][$column] = base64_encode($rewritten);
                }
            }
            yield $this->encode_record($record);
        }
        yield $this->encode_record(['type' => 'end']);
    }

    public function close(): void {
        if (is_resource($this->input)) {
            fclose($this->input);
            $this->input = null;
        }
    }

    /** @param array<string,mixed> $record One table, change, or end record. */
    private function encode_record(array $record): string {
        $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        if (strlen($line) > DatabasePush::MAX_RECORD_BYTES) {
            throw new RuntimeException('A rewritten database changes record exceeds the 2 MiB record limit.');
        }
        return $line;
    }
}
