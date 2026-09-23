<?php

declare(strict_types=1);

namespace Reprint\Importer;

use PDO;
use Reprint\Importer\Database\DatabaseConnection;
use RuntimeException;
use WP_MySQL_Lexer;

/**
 * Converts exported SET masks to the labels stored by the SQLite integration.
 *
 * For SET('a','b'), MySQL uses one bit per declared member: 1 selects 'a',
 * 2 selects 'b', and 3 selects both. The dump exports that number so a MySQL
 * target can restore the exact selection. SQLite stores SET columns as text;
 * importing 3 directly would store '3', not the source label 'a,b'.
 *
 * With `flags` declared as SET('a','b'), this turns:
 *
 *     INSERT INTO `wp_sets` (`flags`) VALUES (3);
 *
 * into:
 *
 *     INSERT INTO `wp_sets` (`flags`) VALUES (FROM_BASE64('YSxi'));
 *
 * FROM_BASE64('YSxi') yields 'a,b'. Using the dump's existing string encoding
 * also handles quotes and backslashes in member names without SQL escaping.
 * SET primary keys in chunk UPDATEs need the same conversion after INSERT.
 *
 * This preserves the displayed label, not hidden SET bits. With SET('','a'),
 * masks 0 and 1 both become '', and masks 2 and 3 both become 'a'. SQLite's
 * text storage cannot keep those selections distinct.
 */
class SqliteSetValueStatementRewriter {
    private DatabaseConnection $database;
    private ?string $table = null;
    /** @var array<string,list<string>> SET members for the current table only. */
    private array $members = [];

    public function __construct(DatabaseConnection $database) {
        $this->database = $database;
    }

    /**
     * Rewrites the INSERT and UPDATE forms emitted by MySQLDumpProducer.
     *
     * The caller executes each rewritten statement before passing the next one,
     * so SHOW FULL COLUMNS sees the preceding CREATE/ALTER. Keep one instance
     * per import group to reuse the current table's members. A resumed group
     * can load them from the target schema without separate saved metadata.
     */
    public function rewrite(string $sql): string {
        $lexer = new WP_MySQL_Lexer($sql);
        if (!$lexer->next_token()) {
            return $sql;
        }
        $tokens = [$lexer->get_token()];
        $insert = $tokens[0]->id === WP_MySQL_Lexer::INSERT_SYMBOL;
        if (!$insert && $tokens[0]->id !== WP_MySQL_Lexer::UPDATE_SYMBOL) {
            // A DROP/CREATE or ALTER between statements may change SET members.
            $this->table = null;
            return $sql;
        }
        // Read only the statement head until we know the table has SET columns.
        // Ordinary WordPress tables need no full-statement lexer pass here.
        $table_index = $insert ? 2 : 1;
        for ($index = 1; $index <= $table_index; ++$index) {
            $lexer->next_token();
            $tokens[] = $lexer->get_token();
        }
        if (!isset($tokens[$table_index]) || $tokens[$table_index]->id !== WP_MySQL_Lexer::BACK_TICK_QUOTED_ID) {
            return $sql;
        }
        $table = $tokens[$table_index]->get_value();
        if ($this->table !== $table) {
            $this->table = $table;
            $this->members = [];
            $result = $this->database->query('SHOW FULL COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            try {
                foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $type_lexer = new WP_MySQL_Lexer($column['Type']);
                    if (!$type_lexer->next_token() || $type_lexer->get_token()->id !== WP_MySQL_Lexer::SET_SYMBOL) {
                        continue;
                    }
                    $members = [];
                    foreach ($type_lexer->remaining_tokens() as $token) {
                        if ($token->id === WP_MySQL_Lexer::SINGLE_QUOTED_TEXT) {
                            $members[] = $token->get_value();
                        }
                    }
                    $this->members[$column['Field']] = $members;
                }
            } finally {
                $result->closeCursor();
            }
        }
        if ($this->members === []) {
            return $sql;
        }
        $tokens = array_merge($tokens, $lexer->remaining_tokens());
        if (end($tokens)->id === WP_MySQL_Lexer::EOF) {
            array_pop($tokens);
        }
        $map = \SqlStatementRewriter::map_values_to_columns_from_tokens($tokens);
        if ($map === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI import error, not HTML.
            throw new RuntimeException('Cannot map exported SET values to SQLite columns in table ' . $table . '.');
        }
        $replacements = [];
        foreach ($map['column_map'] as [$start, $end, $column]) {
            $value = trim(substr($sql, $start, $end - $start));
            if (isset($this->members[$column]) && ctype_digit($value)) {
                $replacements[$start] = [$end - $start, $this->label_literal($value, $this->members[$column])];
            }
        }
        if (!$insert) {
            // Chunk UPDATEs compare an exported SET primary key by unsigned
            // mask. SQLite stores its label, so both sides must change together.
            // For SET('a','b'), CAST(`wp_sets`.`flags` AS UNSIGNED) = 3
            // becomes `wp_sets`.`flags` = FROM_BASE64('YSxi'). Keeping the CAST
            // would compare the numeric conversion of 'a,b' with 3 and miss
            // the row whose large value the UPDATE is meant to finish.
            $token_count = count($tokens);
            for ($index = 0; $index + 9 < $token_count; ++$index) {
                if ($tokens[$index]->id !== WP_MySQL_Lexer::CAST_SYMBOL ||
                    $tokens[$index + 1]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL ||
                    $tokens[$index + 2]->id !== WP_MySQL_Lexer::BACK_TICK_QUOTED_ID ||
                    $tokens[$index + 2]->get_value() !== $table ||
                    $tokens[$index + 3]->id !== WP_MySQL_Lexer::DOT_SYMBOL ||
                    $tokens[$index + 4]->id !== WP_MySQL_Lexer::BACK_TICK_QUOTED_ID ||
                    $tokens[$index + 5]->id !== WP_MySQL_Lexer::AS_SYMBOL ||
                    $tokens[$index + 6]->id !== WP_MySQL_Lexer::UNSIGNED_SYMBOL ||
                    $tokens[$index + 7]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL ||
                    $tokens[$index + 8]->id !== WP_MySQL_Lexer::EQUAL_OPERATOR) {
                    continue;
                }
                $column = $tokens[$index + 4]->get_value();
                $value = $tokens[$index + 9]->get_value();
                if (!isset($this->members[$column]) || !ctype_digit($value)) {
                    continue;
                }
                $start = $tokens[$index]->start;
                $end = $tokens[$index + 9]->start + $tokens[$index + 9]->length;
                $identifier = substr($sql, $tokens[$index + 2]->start, $tokens[$index + 4]->start + $tokens[$index + 4]->length - $tokens[$index + 2]->start);
                $replacements[$start] = [$end - $start, $identifier . ' = ' . $this->label_literal($value, $this->members[$column])];
            }
        }
        krsort($replacements);
        foreach ($replacements as $start => [$length, $replacement]) {
            $sql = substr_replace($sql, $replacement, $start, $length);
        }
        return $sql;
    }

    /**
     * Returns the dump's string literal for the selected members in schema order.
     *
     * Divide the decimal string by two once per member. Each remainder tells
     * whether that member is selected: mask 5 gives remainders 1, 0, 1 and
     * selects the first and third members. Casting the whole mask to PHP int
     * would lose values above PHP_INT_MAX, such as 18446744073709551615.
     *
     * @param string       $mask    Unsigned decimal mask, not a SET label.
     * @param list<string> $members Declared SET members in bit order.
     */
    private function label_literal(string $mask, array $members): string {
        $original_mask = $mask;
        $label = '';
        // Decimal division keeps all 64 bits without depending on PHP's integer
        // width or routing the upper half of the range through a float.
        foreach ($members as $member) {
            $quotient = '';
            $remainder = 0;
            foreach (str_split($mask) as $digit) {
                $value = $remainder * 10 + (int) $digit;
                $quotient .= (string) intdiv($value, 2);
                $remainder = $value % 2;
            }
            if ($remainder !== 0) {
                // MySQL inserts a separator only after non-empty output. An
                // empty first member is indistinguishable from no member here.
                $label .= ( $label === '' ? '' : ',' ) . $member;
            }
            $mask = ltrim($quotient, '0');
            if ($mask === '') {
                break;
            }
        }
        if ($mask !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI import error, not HTML.
            throw new RuntimeException('Exported SET mask ' . $original_mask . ' exceeds the ' . count($members) . ' declared SQLite column members.');
        }
        return "FROM_BASE64('" . base64_encode($label) . "')";
    }
}
