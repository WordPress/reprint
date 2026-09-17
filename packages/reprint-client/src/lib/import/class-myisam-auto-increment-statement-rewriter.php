<?php

declare(strict_types=1);

namespace Reprint\Importer;

use PDO;
use Reprint\Importer\Database\DatabaseConnection;

/**
 * Adapts MyISAM auto-number keys when the target forces InnoDB.
 *
 * SHOW CREATE TABLE retains ENGINE=MyISAM, but a target can override it with
 * enforce_storage_engine. InnoDB cannot number rows within each composite-key
 * prefix; its auto-number column must lead an index.
 */
class MyIsamAutoIncrementStatementRewriter {

    private DatabaseConnection $database;

    public function __construct(DatabaseConnection $database)
    {
        $this->database = $database;
    }

    /**
     * Returns changed SQL and its warning details, or null when no rewrite is needed.
     *
     * The caller executes the returned SQL and reports the warning.
     *
     * @return array|null {
     *     @type string $sql     Statement with the additional non-unique index.
     *     @type string $table   Table named by the CREATE statement.
     *     @type string $column  Auto-increment column needing a leading index.
     *     @type string $message Warning about the index and future ID numbering.
     * }
     */
    public function rewrite(string $sql): ?array
    {
        $lexer = new \WP_MySQL_Lexer($sql);
        if (
            stripos($sql, 'AUTO_INCREMENT') === false ||
            !$lexer->next_token() || $lexer->get_token()->id !== \WP_MySQL_Lexer::CREATE_SYMBOL
        ) {
            return null;
        }
        static $grammar = null;
        if ($grammar === null) {
            $grammar = new \WP_Parser_Grammar(require resolve_sqlite_integration_path(
                '/packages/mysql-on-sqlite/src/mysql/mysql-grammar.php'
            ));
        }
        // Only build a syntax tree for CREATE statements, never for row data.
        $parser = new \WP_MySQL_Parser($grammar, array_merge([$lexer->get_token()], $lexer->remaining_tokens()));
        $statement = $parser->parse();
        $create_table = $statement === null ? null : $statement->get_first_descendant_node('createTable');
        if ($create_table === null) {
            return null;
        }
        $engine = $create_table->get_first_descendant_node('engineRef');
        $elements = $create_table->get_first_child_node('tableElementList');
        if ($engine === null || $elements === null || strcasecmp($engine->get_first_descendant_token()->get_value(), 'MyISAM') !== 0) {
            return null;
        }
        $auto_increment_columns = [];
        $leading_index_columns = [];
        foreach ($elements->get_descendant_nodes('columnDefinition') as $column) {
            $identifier = $column->get_first_child_node('fieldIdentifier')->get_first_descendant_token();
            if ($column->get_first_descendant_token(\WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL) !== null) {
                $auto_increment_columns[] = $identifier;
            }
            if (
                $column->get_first_descendant_token(\WP_MySQL_Lexer::PRIMARY_SYMBOL) !== null ||
                $column->get_first_descendant_token(\WP_MySQL_Lexer::UNIQUE_SYMBOL) !== null
            ) {
                $leading_index_columns[] = $identifier->get_value();
            }
        }
        foreach ($elements->get_descendant_nodes('tableConstraintDef') as $constraint) {
            $key_list = $constraint->get_first_descendant_node('keyListWithExpression');
            if ($key_list === null) {
                continue;
            }
            $first_part = $key_list->get_first_child_node('keyPartOrExpression')->get_first_child_node('keyPart');
            if ($first_part !== null) {
                $leading_index_columns[] = $first_part->get_first_child_node('identifier')->get_first_descendant_token()->get_value();
            }
        }
        // A valid source table has at most one auto-number column. Do not
        // turn invalid input with several such columns into a different schema.
        if (count($auto_increment_columns) !== 1) {
            return null;
        }
        $column = $auto_increment_columns[0];
        foreach ($leading_index_columns as $leading_column) {
            if (strcasecmp($leading_column, $column->get_value()) === 0) {
                return null;
            }
        }
        $result = $this->database->query("SHOW SESSION VARIABLES LIKE 'enforce_storage_engine'");
        $enforced_engine = $result->fetch(PDO::FETCH_ASSOC);
        $result->closeCursor();
        if ($enforced_engine === false || strcasecmp($enforced_engine['Value'], 'InnoDB') !== 0) {
            return null;
        }
        $closing_parenthesis = $create_table->get_first_child_token(\WP_MySQL_Lexer::CLOSE_PAR_SYMBOL);
        // Keep the primary key and existing IDs. An unnamed,
        // non-unique index also lets the server avoid name clashes.
        $sql = substr_replace($sql, ', KEY (' . $column->get_bytes() . ')', $closing_parenthesis->start, 0);
        $table_parts = [];
        foreach ($create_table->get_first_child_node('tableName')->get_descendant_nodes('identifier') as $identifier) {
            $table_parts[] = $identifier->get_first_descendant_token()->get_value();
        }
        $table_name = implode('.', $table_parts);
        $column_name = $column->get_value();
        return [
            'sql' => $sql,
            'table' => $table_name,
            'column' => $column_name,
            'message' => "Warning: The target forces InnoDB. Adding a non-unique index on {$table_name}.{$column_name} " .
                'for AUTO_INCREMENT. Existing IDs are preserved; future IDs use a table-wide sequence ' .
                'instead of per-group sequences. Check code that relies on per-group IDs.',
        ];
    }
}
