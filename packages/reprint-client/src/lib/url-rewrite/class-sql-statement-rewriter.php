<?php

/**
 * Rewrites exact URL-base bytes inside marked, complete text values.
 *
 * Binary values, oversized append fragments, legacy unmarked values, DDL, and
 * statements without the producer marker pass through unchanged.
 */
class SqlStatementRewriter
{
    private StructuredDataUrlRewriter $url_rewriter;

    public function __construct(StructuredDataUrlRewriter $url_rewriter)
    {
        $this->url_rewriter = $url_rewriter;
    }

    /**
     * Rewrite URLs in a SQL statement.
     */
    public function rewrite(string $sql): string
    {
        if (strpos($sql, 'FROM_BASE64(') === false) {
            return $sql;
        }

        if (strpos($sql, '/*reprint:complete-text-v1*/') === false) {
            return $sql;
        }

        // Base64 encodes three source bytes into four output bytes. Across
        // both supported schemes and every possible byte alignment, an
        // encoded HTTP URL contains at least one of these four fragments.
        if (
            strpos($sql, 'aHR0') === false
            && strpos($sql, 'dHA6') === false
            && strpos($sql, 'dHBz') === false
            && strpos($sql, 'dHRw') === false
        ) {
            return $sql;
        }

        $fast_insert = FastInsertScanner::scan($sql);
        if ($fast_insert !== null) {
            return $this->rewrite_with_scanner(
                Base64ValueScanner::from_entries(
                    $sql,
                    $fast_insert['base64_entries']
                )
            );
        }

        return $this->rewrite_with_scanner(new Base64ValueScanner($sql));
    }

    /**
     * Build a SQLite prepared INSERT for a producer-shaped statement.
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
            function (string $value): string {
                return $this->url_rewriter->rewrite($value);
            }
        );
    }

    private function rewrite_with_scanner(Base64ValueScanner $scanner): string
    {
        while ($scanner->next_value()) {
            if (!$scanner->current_value_is_complete_text()) {
                continue;
            }
            if (!$scanner->encoded_payload_could_contain_http_scheme()) {
                continue;
            }

            $value = $scanner->get_value();
            $rewritten = $this->url_rewriter->rewrite($value);
            if ($rewritten !== $value) {
                $scanner->set_value($rewritten);
            }
        }

        return $scanner->get_result();
    }
}
