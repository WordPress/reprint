<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class SQLitePreparedInsertBuilderTest extends TestCase
{
    /**
     * @return string[]
     */
    private function collectValues(string $sql): array
    {
        $values = [];
        $scanner = new Base64ValueScanner($sql);
        while ($scanner->next_value()) {
            $values[] = $scanner->get_value();
        }
        return $values;
    }

    /**
     * @param array $prepared {
     *     SQLite prepared statement.
     *
     *     @type string $sql         SQL with placeholders.
     *     @type array  $params      Decoded parameter values.
     *     @type array  $param_types SQLite parameter types.
     * }
     * @phpstan-param array{sql: string, params: list<mixed>, param_types: list<int>} $prepared
     */
    private function executePrepared(PDO $pdo, array $prepared): void
    {
        $statement = $pdo->prepare($prepared['sql']);
        $this->assertInstanceOf(PDOStatement::class, $statement);
        foreach ($prepared['params'] as $index => $value) {
            $statement->bindValue($index + 1, $value, $prepared['param_types'][$index]);
        }
        $this->assertTrue($statement->execute());
    }

    public function testBuildsPreparedInsertForArbitraryBytes(): void
    {
        $bytes = '';
        for ($i = 0; $i <= 255; $i++) {
            $bytes .= chr($i);
        }

        $sql = sprintf(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode($bytes)
        );

        $prepared = SQLitePreparedInsertBuilder::build($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(CAST(? AS NUMERIC), ?);",
            $prepared['sql']
        );
        $this->assertSame(['1', $bytes], $prepared['params']);
        $this->assertSame([PDO::PARAM_STR, PDO::PARAM_STR], $prepared['param_types']);

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for binary round-trip coverage.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE `wp_options` (`option_id` INTEGER, `option_value` BLOB)');
        $this->executePrepared($pdo, $prepared);

        $row = $pdo->query('SELECT option_id, option_value FROM `wp_options`')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, $row['option_id']);
        $this->assertSame($bytes, $row['option_value']);
    }

    public function testBuildsPreparedInsertForConvertWrappedPayload(): void
    {
        $value = '{"url":"https://example.com"}';
        $sql = sprintf(
            "INSERT INTO `wp_postmeta` (`meta_value`) VALUES(CONVERT(FROM_BASE64('%s') USING utf8mb4));",
            base64_encode($value)
        );

        $prepared = SQLitePreparedInsertBuilder::build($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_postmeta` (`meta_value`) VALUES(?);",
            $prepared['sql']
        );
        $this->assertSame([$value], $prepared['params']);
    }

    public function testRewriteCallbackReceivesTableAndColumn(): void
    {
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode('before')
        );

        $prepared = SQLitePreparedInsertBuilder::build(
            $sql,
            function (string $value, string $table, ?string $column): string {
                $this->assertSame('before', $value);
                $this->assertSame('wp_posts', $table);
                $this->assertSame('post_content', $column);
                return 'after';
            }
        );

        $this->assertNotNull($prepared);
        $this->assertSame(['1', 'after'], $prepared['params']);
    }

    public function testBuildsPreparedInsertForEscapedIdentifiersAndMixedParams(): void
    {
        $payload = "\x00https://example.com/value\xff";
        $sql = sprintf(
            "INSERT INTO `wp``options` (`id`, `empty`, `nullable`, `ratio`, `payload``blob`) VALUES(+7, '', NULL, -3.5e+2, FROM_BASE64('%s'));",
            base64_encode($payload)
        );

        $prepared = SQLitePreparedInsertBuilder::build($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp``options` (`id`, `empty`, `nullable`, `ratio`, `payload``blob`) VALUES(CAST(? AS NUMERIC), ?, ?, CAST(? AS REAL), ?);",
            $prepared['sql']
        );
        $this->assertSame(['+7', '', null, '-3.5e+2', $payload], $prepared['params']);
        $this->assertSame(
            [PDO::PARAM_STR, PDO::PARAM_STR, PDO::PARAM_NULL, PDO::PARAM_STR, PDO::PARAM_STR],
            $prepared['param_types']
        );
    }

    public function testRepeatedShapesCompileToSameTemplateWithDifferentValues(): void
    {
        $sql_a = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`, `post_excerpt`, `menu_order`) VALUES" .
            "(1, FROM_BASE64('%s'), '', NULL)," .
            "(2, CONVERT(FROM_BASE64('%s') USING utf8mb4), FROM_BASE64('%s'), -3.5e+2);",
            base64_encode('alpha'),
            base64_encode('{"url":"https://example.test/a"}'),
            base64_encode('excerpt-a')
        );
        $sql_b = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`, `post_excerpt`, `menu_order`) VALUES" .
            "(10, FROM_BASE64('%s'), '', NULL)," .
            "(20, CONVERT(FROM_BASE64('%s') USING utf8mb4), FROM_BASE64('%s'), 4.25);",
            base64_encode('bravo'),
            base64_encode('{"url":"https://example.test/b"}'),
            base64_encode('excerpt-b')
        );

        $prepared_a = SQLitePreparedInsertBuilder::build($sql_a);
        $prepared_b = SQLitePreparedInsertBuilder::build($sql_b);

        $this->assertNotNull($prepared_a);
        $this->assertNotNull($prepared_b);
        $expected_sql = "INSERT INTO `wp_posts` (`ID`, `post_content`, `post_excerpt`, `menu_order`) VALUES" .
            "(CAST(? AS NUMERIC), ?, ?, ?)," .
            "(CAST(? AS NUMERIC), ?, ?, CAST(? AS REAL));";
        $this->assertSame($expected_sql, $prepared_a['sql']);
        $this->assertSame($prepared_a['sql'], $prepared_b['sql']);
        $this->assertSame(
            ['1', 'alpha', '', null, '2', '{"url":"https://example.test/a"}', 'excerpt-a', '-3.5e+2'],
            $prepared_a['params']
        );
        $this->assertSame(
            ['10', 'bravo', '', null, '20', '{"url":"https://example.test/b"}', 'excerpt-b', '4.25'],
            $prepared_b['params']
        );
        $this->assertSame(
            [
                PDO::PARAM_STR,
                PDO::PARAM_STR,
                PDO::PARAM_STR,
                PDO::PARAM_NULL,
                PDO::PARAM_STR,
                PDO::PARAM_STR,
                PDO::PARAM_STR,
                PDO::PARAM_STR,
            ],
            $prepared_a['param_types']
        );
    }

    public function testRejectsMalformedBase64InsteadOfBindingEmptyString(): void
    {
        $sql = "INSERT INTO `wp_options` (`option_value`) VALUES(FROM_BASE64('valid'));";

        $this->assertNull(SQLitePreparedInsertBuilder::build($sql));
    }

    public function testRejectsMalformedBase64AfterSameShapeWasBuilt(): void
    {
        $valid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('name'),
            base64_encode('value')
        );
        $this->assertNotNull(SQLitePreparedInsertBuilder::build($valid_sql));

        // Guards both the current rescan path and any future same-shape cache.
        $invalid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('valid'));",
            base64_encode('name')
        );

        $this->assertNull(SQLitePreparedInsertBuilder::build($invalid_sql));
    }

    public function testRejectsNonAlphabetPayloadAfterSameShapeWasBuilt(): void
    {
        $valid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('name'),
            base64_encode('value')
        );
        $this->assertNotNull(SQLitePreparedInsertBuilder::build($valid_sql));

        // Guards both the current rescan path and any future same-shape cache.
        $invalid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('not-valid!'));",
            base64_encode('name')
        );

        $this->assertNull(SQLitePreparedInsertBuilder::build($invalid_sql));
    }

    public function testUnknownInsertShapeReturnsNull(): void
    {
        $sql = sprintf(
            "INSERT INTO `wp_options` VALUES(1, FROM_BASE64('%s'));",
            base64_encode('value')
        );

        $this->assertNull(SQLitePreparedInsertBuilder::build($sql));
    }

    public function testRejectsFromBase64InsideStringLiteral(): void
    {
        $sql = "INSERT INTO `wp_options` (`option_value`) VALUES('FROM_BASE64(''dmFsdWU='')');";

        $this->assertNull(SQLitePreparedInsertBuilder::build($sql));
    }

    public function testRejectsFromBase64InsideComment(): void
    {
        $sql = "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(/* FROM_BASE64('dmFsdWU=') */ 1, '');";

        $this->assertNull(SQLitePreparedInsertBuilder::build($sql));
    }

    public function testFastInsertScannerHeaderWhitespaceStillBuildsPreparedInsert(): void
    {
        $sql = sprintf(
            "INSERT\fINTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode('value')
        );

        $prepared = SQLitePreparedInsertBuilder::build($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(CAST(? AS NUMERIC), ?);",
            $prepared['sql']
        );
        $this->assertSame(['1', 'value'], $prepared['params']);
    }

    public function testMalformedProducerShapesReturnNull(): void
    {
        $encoded = base64_encode('value');

        $this->assertNull(SQLitePreparedInsertBuilder::build(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1e, FROM_BASE64('{$encoded}'));"
        ));
        $this->assertNull(SQLitePreparedInsertBuilder::build(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('{$encoded}'),);"
        ));
        $this->assertNull(SQLitePreparedInsertBuilder::build(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, 'literal');"
        ));
    }

    public function testNumericLiteralStorageClassesMatchSQLiteLiteralSemantics(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for numeric storage-class coverage.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cases = ['1', '-3.5e+2', '4.25', '18446744073709551615', '1.', '1e2'];

        foreach ($cases as $index => $literal) {
            $table = 't' . $index;
            $pdo->exec("CREATE TABLE `{$table}` (`n`, `payload`)");
            $sql = sprintf(
                "INSERT INTO `{$table}` (`n`, `payload`) VALUES({$literal}, FROM_BASE64('%s'));",
                base64_encode('value')
            );
            $prepared = SQLitePreparedInsertBuilder::build($sql);
            $this->assertNotNull($prepared);
            $this->executePrepared($pdo, $prepared);

            $expected_type = $pdo->query("SELECT typeof({$literal})")->fetchColumn();
            $actual_type = $pdo->query("SELECT typeof(`n`) FROM `{$table}`")->fetchColumn();
            $this->assertSame($expected_type, $actual_type, "literal {$literal}");
        }
    }

    public function testUnsupportedPreparedShapeCanStillFallBackToSqlRewriter(): void
    {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
        $sql = sprintf(
            "INSERT LOW_PRIORITY IGNORE INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode('<a href="https://old-site.com/page">Link</a>')
        );

        $this->assertNull($rewriter->build_sqlite_prepared_insert($sql));

        $rewritten = $rewriter->rewrite($sql);
        $values = $this->collectValues($rewritten);
        $this->assertCount(1, $values);
        $this->assertSame('<a href="https://new-site.com/page">Link</a>', $values[0]);
    }

    public function testSqlStatementRewriterBuildsPreparedInsertWithRewrittenParam(): void
    {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode('<a href="https://old-site.com/page">Link</a>')
        );

        $prepared = $rewriter->build_sqlite_prepared_insert($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(CAST(? AS NUMERIC), ?);",
            $prepared['sql']
        );
        $this->assertSame('1', $prepared['params'][0]);
        $this->assertSame('<a href="https://new-site.com/page">Link</a>', $prepared['params'][1]);
    }

    public function testSqlStatementRewriterUsesColumnOrderWhenCompilingTemplate(): void
    {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(7, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('Title https://old-site.com/title'),
            base64_encode('<a href="https://old-site.com/body">Body</a>')
        );

        $prepared = $rewriter->build_sqlite_prepared_insert($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(CAST(? AS NUMERIC), ?, ?);",
            $prepared['sql']
        );
        $this->assertSame('7', $prepared['params'][0]);
        $this->assertSame('Title https://new-site.com/title', $prepared['params'][1]);
        $this->assertSame('<a href="https://new-site.com/body">Body</a>', $prepared['params'][2]);
    }
}
