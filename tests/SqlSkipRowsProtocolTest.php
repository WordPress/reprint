<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-server/src/server.php';

final class SqlSkipRowsProtocolTest extends TestCase
{
    public function testMapsTableNameWithoutPrefixRuleToRowExclusion(): void
    {
        $this->assertSame(
            [
                [
                    'table' => 'wp_123_postmeta',
                    'column' => 'meta_key',
                    'value' => '_edit_lock',
                ],
            ],
            sql_exclude_rows_from_config(
                [
                    'skip_rows' => [
                        [
                            'table_name_without_prefix' => 'postmeta',
                            'column' => 'meta_key',
                            'value_base64' => base64_encode('_edit_lock'),
                        ],
                    ],
                ],
                'wp_123_'
            )
        );
    }

    public function testMapsExplicitTableRuleToRowExclusion(): void
    {
        $this->assertSame(
            [
                [
                    'table' => 'custom_table',
                    'column' => 'cache_key',
                    'value' => "raw\0bytes",
                ],
            ],
            sql_exclude_rows_from_config(
                [
                    'skip_rows' => [
                        [
                            'table' => 'custom_table',
                            'column' => 'cache_key',
                            'value_base64' => base64_encode("raw\0bytes"),
                        ],
                    ],
                ],
                null
            )
        );
    }

    public function testAcceptsJsonEncodedSkipRows(): void
    {
        $this->assertSame(
            [
                [
                    'table' => 'wp_postmeta',
                    'column' => 'meta_key',
                    'value' => '_edit_lock',
                ],
            ],
            sql_exclude_rows_from_config(
                [
                    'skip_rows' => json_encode([
                        [
                            'table_name_without_prefix' => 'postmeta',
                            'column' => 'meta_key',
                            'value_base64' => base64_encode('_edit_lock'),
                        ],
                    ]),
                ],
                'wp_'
            )
        );
    }

    public function testRejectsTableNameWithoutPrefixWhenTablePrefixIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('table_name_without_prefix requires a table_prefix');

        sql_exclude_rows_from_config(
            [
                'skip_rows' => [
                    [
                        'table_name_without_prefix' => 'postmeta',
                        'column' => 'meta_key',
                        'value_base64' => base64_encode('_edit_lock'),
                    ],
                ],
            ],
            null
        );
    }

    public function testRejectsInvalidBase64Value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('value_base64 must be valid base64');

        sql_exclude_rows_from_config(
            [
                'skip_rows' => [
                    [
                        'table_name_without_prefix' => 'postmeta',
                        'column' => 'meta_key',
                        'value_base64' => 'not valid base64!',
                    ],
                ],
            ],
            'wp_'
        );
    }
}
