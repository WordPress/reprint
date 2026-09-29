<?php

namespace WordPress\Reprint\Server;

/** Byte-preserving values shared by local diffs and production conflict checks. */
final class DatabaseRowFormat {
    public static function select_expression(DatabaseRowsReader $reader, string $column): string {
        $identifier = DatabasePush::identifier($column);
        $data_type = strtoupper($reader->get_data_type($column));
        // Match pull's byte-preserving reads: latin1 E9 must
        // remain E9, not become UTF-8 C3A9 on this connection.
        $value = $reader->is_numeric_type($data_type)
            ? $reader->get_numeric_value_expression($column)
            : 'CAST(' . $identifier . ' AS BINARY)';
        if (in_array($data_type, ['FLOAT', 'DOUBLE', 'REAL'], true)) {
            // PDO can return a float while mysqli returns text.
            // Ask MySQL for text on both, after the shared FLOAT
            // promotion, so changing PHP drivers cannot invent edits.
            $value = 'CAST(' . $value . ' AS CHAR)';
        }
        if ($data_type === 'ENUM') {
            // ENUM index 0 and a declared empty label both read as
            // ''. Preserve the index: 0: differs from 1:.
            $value = "CONCAT(CAST(" . $identifier . " AS UNSIGNED),':',CAST(" . $identifier . ' AS BINARY))';
        }
        return $value;
    }
}
