<?php

/**
 * Combines Base64ValueScanner and StructuredDataUrlRewriter to rewrite URLs
 * in an entire SQL statement.
 *
 * Only modifies INSERT and UPDATE statements containing FROM_BASE64() expressions.
 * DDL statements (CREATE TABLE, ALTER TABLE, etc.) pass through unchanged.
 *
 * Column-aware: for each FROM_BASE64() match, determines which column it belongs
 * to and passes the appropriate content type hint to StructuredDataUrlRewriter.
 * WordPress core columns known to contain block markup (post_content, comment_content,
 * etc.) get the 'block_markup' hint so BlockMarkupUrlProcessor handles HTML
 * attributes, block comment JSON, and CSS url(). All other columns default to
 * auto-detect with URLInTextProcessor for leaf text, which is simpler and more
 * predictable for columns that contain serialized PHP, JSON, or plain strings.
 *
 * Column resolution walks the lexer output directly. Both INSERT and UPDATE
 * statements emitted by MySQLDumpProducer follow a constrained set of shapes
 * the walker recognises; for any shape it doesn't, it returns null and every
 * FROM_BASE64() value falls back to plain-text URL rewriting (which is the
 * safe default anyway — block_markup is only ever assigned to a small set of
 * WordPress core columns).
 */
class SqlStatementRewriter
{
    private StructuredDataUrlRewriter $url_rewriter;

    /** @var array<string, array<string, string>> full_table_name => [column_name => content_type] */
    private array $db_columns_with_block_markup;

    /**
     * WordPress core columns that contain block markup and benefit from
     * BlockMarkupUrlProcessor over plain-text URL scanning. Keyed by table suffix
     * (without prefix) — the constructor prepends the actual table_prefix to
     * build full table names for exact matching.
     *
     * @TODO: Make this extensible, find a way to treat the relevant columns from plugin tables.
     */
    private const WP_BLOCK_MARKUP_COLUMNS = [
        'posts' => [
            'post_content' => 'block_markup',
            'post_content_filtered' => 'block_markup',
            'post_excerpt' => 'block_markup',
        ],
        'comments' => [
            'comment_content' => 'block_markup',
        ],
        'term_taxonomy' => [
            'description' => 'block_markup',
        ],
    ];

    /**
     * @param StructuredDataUrlRewriter $url_rewriter
     * @param string $table_prefix WordPress table prefix (e.g. "wp_"), used to
     *        build full table names for exact matching.
     * @param array<string, array<string, string>> $extra_db_columns_with_block_markup Consumer-provided hints:
     *        table_suffix => [column_name => content_type]. Merged on top of WordPress defaults.
     */
    public function __construct(StructuredDataUrlRewriter $url_rewriter, string $table_prefix = 'wp_', array $extra_db_columns_with_block_markup = [])
    {
        $this->url_rewriter = $url_rewriter;

        // Merge WP defaults with consumer hints (both keyed by suffix),
        // then prepend the table prefix to build full table names.
        // array_replace_recursive so consumer hints override WP defaults
        // for the same table+column (e.g. marking post_content as 'skip').
        $by_suffix = array_replace_recursive(
			self::WP_BLOCK_MARKUP_COLUMNS,
			$extra_db_columns_with_block_markup
        );
        $this->db_columns_with_block_markup = [];
        foreach ($by_suffix as $suffix => $columns) {
            // Some plugins create unprefixed tables, so we match both with and without the table
            // prefix.
            $this->db_columns_with_block_markup[$table_prefix . $suffix] = $columns;
            $this->db_columns_with_block_markup[$suffix] = $columns;
        }
    }

    /**
     * Rewrite URLs in a SQL statement.
     *
     * NOTE: base64-encoded values that do not contain the string "http" are
     * skipped entirely — column resolution and the StructuredDataUrlRewriter
     * pipeline are never run for them. This means URLs stored in base64
     * without an http/https scheme will not be rewritten.
     *
     * @param string $sql The SQL statement.
     * @return string The modified SQL statement.
     */
    public function rewrite(string $sql): string
    {
        // Quick check: if no base64 values, nothing to rewrite
        if (strpos($sql, "FROM_BASE64(") === false) {
            return $sql;
        }

        // Quick check: if none of the base64 encodings of "http" appear in
        // the statement, no value can carry a rewritable URL.
        //
        // base64 encodes 3 source bytes into 4 output chars, so the encoding
        // of "http://" or "https://" depends on which byte boundary the
        // scheme starts on. Across both schemes and all three alignments,
        // the encoded payload always contains at least one of these
        // substrings:
        //
        //   aHR0  encodes "htt"  — "http" / "https" at offset 0 mod 3
        //   dHA6  encodes "tp:"  — "http://" at offset 1 mod 3
        //   dHBz  encodes "tps"  — "https://" at offset 1 mod 3
        //   dHRw  encodes "ttp"  — "http"/"https" at offset 2 mod 3
        //
        // If none appear in the SQL, no FROM_BASE64() payload can decode to
        // a string containing "http", so the per-value strpos('http') check
        // inside rewrite_with_scanner() would short-circuit every value
        // anyway. Skipping the value-walk altogether is ~280× faster on
        // dumps that carry no URLs (microbench: 3.7 s → 13 ms on a 30k-post,
        // 15 MB dump). On URL-heavy dumps the four strpos calls hit on the
        // first match and stay within measurement noise.
        //
        // False positives are tolerable — they cost an extra rewrite pass
        // that finds nothing. False negatives would silently leave URLs
        // un-rewritten; the four prefixes above are the minimum set that
        // covers every alignment×scheme combination, so no real URL escapes.
        if (
            strpos($sql, 'aHR0') === false
            && strpos($sql, 'dHA6') === false
            && strpos($sql, 'dHBz') === false
            && strpos($sql, 'dHRw') === false
        ) {
            return $sql;
        }

        // Fast path: producer-shape INSERTs (the overwhelming majority of
        // statements in a typical dump) are recovered with strpos / regex
        // and never touch WP_MySQL_Lexer. Anything the fast scanner doesn't
        // recognise — UPDATE, INSERT … SELECT, qualified names, hex
        // literals, escaped quotes — falls through to the lexer path
        // below with identical behaviour.
        $fast = FastInsertScanner::scan($sql);
        if ($fast !== null) {
            $value_to_column_map = [
                'table' => $fast['table'],
                'column_map' => $fast['column_map'],
            ];
            $scanner = Base64ValueScanner::from_entries($sql, $fast['base64_entries']);
            return $this->rewrite_with_scanner($scanner, $value_to_column_map);
        }

        // Slow path: lex once, share the token array between the column
        // map walker and Base64ValueScanner.
        $tokens = self::significant_tokens($sql);
        $value_to_column_map = $this->map_values_to_columns_from_tokens($tokens);
        $scanner = new Base64ValueScanner($sql, $tokens);
        return $this->rewrite_with_scanner($scanner, $value_to_column_map);
    }

    /**
     * Build a SQLite prepared INSERT for producer-shaped statements.
     *
     * This follows the same column-aware URL rewriting decisions as rewrite()
     * but returns SQL with placeholders plus decoded parameter bytes. Unknown
     * statement shapes return null so callers can fall back to the normal
     * MySQL-on-SQLite execution path.
     *
     * @return array|null {
     *     SQLite prepared statement, or null for an unsupported statement shape.
     *
     *     @type string $sql         SQL with placeholders.
     *     @type array  $params      Decoded parameter values.
     *     @type array  $param_types SQLite parameter types.
     * }
     * @phpstan-return array{sql: string, params: list<mixed>, param_types: list<int>}|null
     */
    public function build_sqlite_prepared_insert(string $sql): ?array
    {
        return SQLitePreparedInsertBuilder::build(
            $sql,
            function (string $value, string $table, ?string $column): string {
                return $this->rewrite_value_for_column($value, $table, $column);
            }
        );
    }

    private function rewrite_value_for_column(string $value, string $table, ?string $column): string
    {
        if (strpos($value, 'http') === false) {
            return $value;
        }

        if (!$this->url_rewriter->value_might_contain_source_domain($value)) {
            return $value;
        }

        $content_type = $column !== null
            ? $this->get_content_type($table, $column)
            : null;

        // Rewrite URLs in the value. Known block-markup columns go through
        // the structured block parser so alternate URL spellings (for example
        // escaped JSON, uppercase schemes/hosts, and IDNs) are handled by the
        // URL model instead of byte-level replacement.
        return $content_type === StructuredDataUrlRewriter::BLOCK_MARKUP
            ? $this->url_rewriter->rewrite_known_block_markup_value($value)
            : $this->url_rewriter->rewrite($value, $content_type);
    }

    /**
     * Run the value-rewriting loop given an already-populated scanner and
     * the table/column-map context. Shared between the fast and lexer paths.
     *
     * @param array|null $value_to_column_map {
     *     Table and column ranges for the current statement, or null when the
     *     statement shape is unknown.
     *
     *     @type string $table      Table name.
     *     @type array  $column_map Value ranges mapped to column names.
     * }
     * @phpstan-param array{table: string, column_map: list<array{int, int, string}>}|null $value_to_column_map
     */
    private function rewrite_with_scanner(Base64ValueScanner $scanner, ?array $value_to_column_map): string
    {
        while ($scanner->next_value()) {
            if (!$scanner->encoded_payload_could_contain_http_scheme()) {
                continue;
            }

            $value = $scanner->get_value();

            if (strpos($value, 'http') === false) {
                continue;
            }

            if (!$this->url_rewriter->value_might_contain_source_domain($value)) {
                continue;
            }

            // Determine content type hint for this column.
            $column_name = null;
            if ($value_to_column_map !== null) {
                $column_name = $this->find_column_at_offset(
                    $value_to_column_map['column_map'],
                    $scanner->get_match_offset()
                );
            }

            $rewritten = $this->rewrite_value_for_column(
                $value,
                $value_to_column_map['table'] ?? '',
                $column_name
            );

            // Only replace if the value actually changed
            if ($rewritten !== $value) {
                $scanner->set_value($rewritten);
            }
        }

        return $scanner->get_result();
    }

    /**
     * Walk the lexer output to recover, for an INSERT or UPDATE statement,
     * the byte-offset→column map needed to give each FROM_BASE64() value
     * the right content-type hint.
     *
     * Recognised shapes:
     *
     *   INSERT [LOW_PRIORITY|DELAYED|HIGH_PRIORITY] [IGNORE] INTO `t`
     *     (`c1`, `c2`, …) [VALUES|VALUE]
     *     [ROW]?(e1, e2, …) [, [ROW]?(…), …]
     *     [ON DUPLICATE KEY UPDATE …]?
     *     [;]?
     *
     *   REPLACE [LOW_PRIORITY|DELAYED] INTO `t` (… same shape …)
     *
     *   UPDATE [LOW_PRIORITY] [IGNORE] `t`
     *     SET `c1` = e1 [, `c2` = e2, …]
     *     [WHERE … | ORDER BY … | LIMIT …]?
     *     [;]?
     *
     * Anything else — INSERT … SELECT, INSERT … SET col=v, INSERT without a
     * column list, qualified names like `db`.`t`, multi-table UPDATE — returns
     * null. The caller treats null the same as an empty column_map: every
     * FROM_BASE64() value falls through to plain-text URL rewriting, which
     * is the safe default. URL rewriting still happens; only the
     * block_markup hint (relevant for ~5 WordPress core columns) is lost.
     *
     * The lexer already handles strings, comments, escaped backticks, hex /
     * binary / null literals and so on, so the walker only needs to track
     * parenthesis depth at the token level — string-literal tokens that
     * contain `(`, `)`, `,` etc. arrive as a single token and never affect
     * depth.
     *
     * @return array|null {
     *     Table and column ranges, or null when the statement shape is unknown.
     *
     *     @type string $table      Table name.
     *     @type array  $column_map Value ranges mapped to column names.
     * }
     * @phpstan-return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    // Called via reflection in SqlStatementRewriterLexerWalkerTest.
    // @phpstan-ignore method.unused
    private function map_values_to_columns(string $sql): ?array
    {
        return $this->map_values_to_columns_from_tokens(self::significant_tokens($sql));
    }

    /**
     * Consumes a pre-lexed token array. Lets callers that already lexed the
     * statement avoid a second WP_MySQL_Lexer pass.
     *
     * @param WP_MySQL_Token[] $tokens
     * @return array|null {
     *     Table and column ranges, or null when the statement shape is unknown.
     *
     *     @type string $table      Table name.
     *     @type array  $column_map Value ranges mapped to column names.
     * }
     * @phpstan-return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    private function map_values_to_columns_from_tokens(array $tokens): ?array
    {
        $token_count = count($tokens);
        if ($token_count < 4) {
            return null;
        }

        $cursor = 0;
        $first_keyword_id = $tokens[$cursor]->id;

        if (
            $first_keyword_id === WP_MySQL_Lexer::INSERT_SYMBOL
            || $first_keyword_id === WP_MySQL_Lexer::REPLACE_SYMBOL
        ) {
            return self::walk_insert($tokens, $token_count, $cursor);
        }

        if ($first_keyword_id === WP_MySQL_Lexer::UPDATE_SYMBOL) {
            return self::walk_update($tokens, $token_count, $cursor);
        }

        return null;
    }

    /**
     * Walk the body of an INSERT / REPLACE statement starting at
     * `$cursor` (which already points at the leading verb).
     *
     * @param WP_MySQL_Token[] $tokens
     * @return array|null {
     *     Table and column ranges, or null when the statement shape is unknown.
     *
     *     @type string $table      Table name.
     *     @type array  $column_map Value ranges mapped to column names.
     * }
     * @phpstan-return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    private static function walk_insert(array $tokens, int $token_count, int $cursor): ?array
    {
        // Step past the leading INSERT or REPLACE.
        $cursor++;

        // Optional priority + IGNORE modifiers in any order MySQL accepts.
        // An unrecognised modifier drops us out of the fast path.
        while ($cursor < $token_count) {
            $modifier_id = $tokens[$cursor]->id;
            if (
                $modifier_id === WP_MySQL_Lexer::LOW_PRIORITY_SYMBOL
                || $modifier_id === WP_MySQL_Lexer::DELAYED_SYMBOL
                || $modifier_id === WP_MySQL_Lexer::HIGH_PRIORITY_SYMBOL
                || $modifier_id === WP_MySQL_Lexer::IGNORE_SYMBOL
            ) {
                $cursor++;
                continue;
            }
            break;
        }

        // INTO
        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::INTO_SYMBOL) {
            return null;
        }
        $cursor++;

        // Table identifier. Reject qualified names — `db`.`t` would mean we
        // got the database wrong, and the column_map keys are matched by
        // bare table name anyway.
        if ($cursor >= $token_count) {
            return null;
        }
        $table_token_id = $tokens[$cursor]->id;
        $table_name = ($table_token_id === WP_MySQL_Lexer::BACK_TICK_QUOTED_ID || $table_token_id === WP_MySQL_Lexer::IDENTIFIER)
            ? $tokens[$cursor]->get_value()
            : null;
        if ($table_name === null) {
            return null;
        }
        $cursor++;
        if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::DOT_SYMBOL) {
            return null;
        }

        // Required column list `( col, col, … )`. Without column names there's
        // nothing to map FROM_BASE64() byte offsets against.
        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
            return null;
        }
        $cursor++;

        $column_names = [];
        while ($cursor < $token_count && $tokens[$cursor]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
            $column_token_id = $tokens[$cursor]->id;
            $column_name = ($column_token_id === WP_MySQL_Lexer::BACK_TICK_QUOTED_ID || $column_token_id === WP_MySQL_Lexer::IDENTIFIER)
                ? $tokens[$cursor]->get_value()
                : null;
            if ($column_name === null) {
                return null;
            }
            $column_names[] = $column_name;
            $cursor++;
            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::COMMA_SYMBOL) {
                $cursor++;
                continue;
            }
            break;
        }
        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
            return null;
        }
        $cursor++;

        // VALUES (or its singular alias VALUE).
        if (
            $cursor >= $token_count
            || (
                $tokens[$cursor]->id !== WP_MySQL_Lexer::VALUES_SYMBOL
                && $tokens[$cursor]->id !== WP_MySQL_Lexer::VALUE_SYMBOL
            )
        ) {
            return null;
        }
        $cursor++;

        $column_count = count($column_names);
        $column_map = [];
        while ($cursor < $token_count) {
            // Optional ROW prefix (MySQL 8.0+ explicit row constructor).
            if ($tokens[$cursor]->id === WP_MySQL_Lexer::ROW_SYMBOL) {
                $cursor++;
                if ($cursor >= $token_count) {
                    return null;
                }
            }

            if ($tokens[$cursor]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                return null;
            }
            $cursor++; // step past `(`

            $column_index_in_row = 0;
            $expression_starts_at_token = $cursor;
            $paren_depth_inside_tuple = 0;
            $tuple_was_closed = false;
            while ($cursor < $token_count) {
                $current_token_id = $tokens[$cursor]->id;
                if ($current_token_id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                    $paren_depth_inside_tuple++;
                } elseif ($current_token_id === WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                    if ($paren_depth_inside_tuple === 0) {
                        if ($column_index_in_row < $column_count && $expression_starts_at_token < $cursor) {
                            $expression_first_token = $tokens[$expression_starts_at_token];
                            $expression_last_token = $tokens[$cursor - 1];
                            $column_map[] = [
                                $expression_first_token->start,
                                $expression_last_token->start + $expression_last_token->length,
                                $column_names[$column_index_in_row],
                            ];
                        }
                        $tuple_was_closed = true;
                        break;
                    }
                    $paren_depth_inside_tuple--;
                } elseif ($current_token_id === WP_MySQL_Lexer::COMMA_SYMBOL && $paren_depth_inside_tuple === 0) {
                    if ($column_index_in_row < $column_count && $expression_starts_at_token < $cursor) {
                        $expression_first_token = $tokens[$expression_starts_at_token];
                        $expression_last_token = $tokens[$cursor - 1];
                        $column_map[] = [
                            $expression_first_token->start,
                            $expression_last_token->start + $expression_last_token->length,
                            $column_names[$column_index_in_row],
                        ];
                    }
                    $column_index_in_row++;
                    $expression_starts_at_token = $cursor + 1;
                }
                $cursor++;
            }
            if (!$tuple_was_closed) {
                return null;
            }
            $cursor++; // step past `)`

            // Another tuple, statement terminator, or trailer keyword.
            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::COMMA_SYMBOL) {
                $cursor++;
                continue;
            }
            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::SEMICOLON_SYMBOL) {
                $cursor++;
            }
            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::ON_SYMBOL) {
                // ON DUPLICATE KEY UPDATE … — anything past here is the
                // assignment list, which doesn't carry value-tuple
                // FROM_BASE64() the rewriter would map. Stop reading.
                break;
            }
            if ($cursor !== $token_count) {
                return null;
            }
            break;
        }

        return ['table' => $table_name, 'column_map' => $column_map];
    }

    /**
     * Walk the body of an UPDATE statement starting at `$cursor` (which
     * points at the leading UPDATE keyword).
     *
     * Single-table updates only: an unqualified table identifier followed
     * by SET, then a comma-separated list of `col = expression` assignments.
     * The expression range runs to the next comma at depth 0, or to the
     * first occurrence of WHERE / ORDER / LIMIT / `;` / end of input.
     *
     * @param WP_MySQL_Token[] $tokens
     * @return array|null {
     *     Table and column ranges, or null when the statement shape is unknown.
     *
     *     @type string $table      Table name.
     *     @type array  $column_map Value ranges mapped to column names.
     * }
     * @phpstan-return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    private static function walk_update(array $tokens, int $token_count, int $cursor): ?array
    {
        // Step past UPDATE.
        $cursor++;

        while ($cursor < $token_count) {
            $modifier_id = $tokens[$cursor]->id;
            if (
                $modifier_id === WP_MySQL_Lexer::LOW_PRIORITY_SYMBOL
                || $modifier_id === WP_MySQL_Lexer::IGNORE_SYMBOL
            ) {
                $cursor++;
                continue;
            }
            break;
        }

        if ($cursor >= $token_count) {
            return null;
        }
        $table_token_id = $tokens[$cursor]->id;
        $table_name = ($table_token_id === WP_MySQL_Lexer::BACK_TICK_QUOTED_ID || $table_token_id === WP_MySQL_Lexer::IDENTIFIER)
            ? $tokens[$cursor]->get_value()
            : null;
        if ($table_name === null) {
            return null;
        }
        $cursor++;
        if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::DOT_SYMBOL) {
            return null;
        }

        // SET keyword.
        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::SET_SYMBOL) {
            return null;
        }
        $cursor++;

        // Reject multi-table UPDATEs by refusing anything that looks like a
        // join after the table name. (The walk above already accepts only a
        // single bare identifier, so we don't reach SET on multi-table
        // updates — this is just a guard for the unusual cases.)
        $column_map = [];
        while ($cursor < $token_count) {
            // Column being assigned to.
            $assigned_column_token_id = $tokens[$cursor]->id;
            $assigned_column_name = ($assigned_column_token_id === WP_MySQL_Lexer::BACK_TICK_QUOTED_ID || $assigned_column_token_id === WP_MySQL_Lexer::IDENTIFIER)
                ? $tokens[$cursor]->get_value()
                : null;
            if ($assigned_column_name === null) {
                return null;
            }
            $cursor++;
            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::DOT_SYMBOL) {
                // `t.col = …` form — qualified column ref, give up on the fast
                // path so the rewriter falls back to plain-text rewriting.
                return null;
            }

            // EQUAL_OPERATOR, expressed as `=` token.
            if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::EQUAL_OPERATOR) {
                return null;
            }
            $cursor++;

            // Walk the expression until comma at depth 0, or a clause keyword.
            $expression_starts_at_token = $cursor;
            $paren_depth_in_expression = 0;
            while ($cursor < $token_count) {
                $current_token_id = $tokens[$cursor]->id;
                if ($current_token_id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                    $paren_depth_in_expression++;
                } elseif ($current_token_id === WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                    if ($paren_depth_in_expression === 0) {
                        // Stray `)` at depth 0 means the statement is malformed
                        // or shaped in a way we don't understand.
                        return null;
                    }
                    $paren_depth_in_expression--;
                } elseif ($paren_depth_in_expression === 0) {
                    if (
                        $current_token_id === WP_MySQL_Lexer::COMMA_SYMBOL
                        || $current_token_id === WP_MySQL_Lexer::WHERE_SYMBOL
                        || $current_token_id === WP_MySQL_Lexer::ORDER_SYMBOL
                        || $current_token_id === WP_MySQL_Lexer::LIMIT_SYMBOL
                        || $current_token_id === WP_MySQL_Lexer::SEMICOLON_SYMBOL
                    ) {
                        break;
                    }
                }
                $cursor++;
            }

            if ($cursor === $expression_starts_at_token) {
                // Empty expression on the right of `=` — malformed.
                return null;
            }

            $expression_first_token = $tokens[$expression_starts_at_token];
            $expression_last_token = $tokens[$cursor - 1];
            $column_map[] = [
                $expression_first_token->start,
                $expression_last_token->start + $expression_last_token->length,
                $assigned_column_name,
            ];

            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::COMMA_SYMBOL) {
                $cursor++;
                continue;
            }
            break;
        }

        return ['table' => $table_name, 'column_map' => $column_map];
    }

    /**
     * Find which column a FROM_BASE64() expression belongs to by checking
     * which expression range contains the given byte offset.
     *
     * The column_map is built by parse_insert / parse_update by walking the
     * statement in source order, so it's already sorted by `start` offset
     * and the ranges never overlap. That lets us use a binary search instead
     * of the obvious linear scan — a multi-row INSERT can produce thousands
     * of entries and we look up an offset for every FROM_BASE64() value, so
     * the linear cost was quadratic in row count and showed up as ~150 µs per
     * lookup under WASM PHP.
     *
     * @param array $column_map {
     *     Column range entries.
     *
     *     @type int    $0 Start byte offset.
     *     @type int    $1 End byte offset.
     *     @type string $2 Column name.
     * }
     * @phpstan-param list<array{int, int, string}> $column_map
     * @param int $offset Byte offset of the CONVERT or FROM_BASE64 token.
     * @return string|null Column name, or null if the offset isn't in any range.
     */
    private function find_column_at_offset(array $column_map, int $offset): ?string
    {
        $low = 0;
        $high = count($column_map) - 1;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            $entry = $column_map[$mid];
            if ($offset < $entry[0]) {
                $high = $mid - 1;
            } elseif ($offset >= $entry[1]) {
                $low = $mid + 1;
            } else {
                return $entry[2];
            }
        }
        return null;
    }

    /**
     * Lex the statement and drop the lexer's terminal EOF marker.
     *
     * `WP_MySQL_Lexer::next_token()` already skips whitespace and comment
     * tokens internally, so the array we get back is already
     * structure-only. The only token left to filter is the EOF that the
     * lexer appends after the last real token, which would otherwise trip
     * the walker's "trailing tokens after the last value tuple" guard.
     *
     * @return WP_MySQL_Token[]
     */
    private static function significant_tokens(string $sql): array
    {
        $lexer = new WP_MySQL_Lexer($sql);
        $tokens = $lexer->remaining_tokens();
        if (
            !empty($tokens)
            && end($tokens)->id === WP_MySQL_Lexer::EOF
        ) {
            array_pop($tokens);
        }
        return $tokens;
    }

    /**
     * Look up the content type for a given table and column. The
     * db_columns_with_block_markup map is keyed by full table name (prefix
     * already applied at construction time), so this is a direct lookup.
     *
     * Returns null if there's no entry for this table+column, meaning
     * auto-detect with plain text default.
     */
    private function get_content_type(string $table, string $column): ?string
    {
        return $this->db_columns_with_block_markup[$table][$column] ?? null;
    }
}
