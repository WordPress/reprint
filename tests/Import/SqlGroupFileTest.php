<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class SqlGroupFileTest extends TestCase
{
    private const MARKER = '-- REPRINT SQL GROUP 82d10e87-ec1b-4aa2-a522-963dc82b6bb1 ';

    public function testReadsTheSqlAndCursorFromEachExporterGroup(): void
    {
        $first_cursor = base64_encode(json_encode(['current_table' => 'wp_posts']));
        $second_cursor = base64_encode(json_encode(['current_table' => null]));
        $first_group = "INSERT INTO `wp_posts` VALUES (1);\n";
        $first_marker = self::MARKER . $first_cursor . "\n";
        $second_group = "COMMIT;\n";
        $second_marker = self::MARKER . $second_cursor . "\n";
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $first_group . $first_marker . $second_group . $second_marker);
        rewind($handle);

        $client = ( new \ReflectionClass(\ImportClient::class) )->newInstanceWithoutConstructor();
        $read_group = new \ReflectionMethod(\ImportClient::class, 'read_next_sql_group');
        $read_group->setAccessible(true);

        $first = $read_group->invoke($client, $handle);
        $this->assertSame($first_group, $first['sql']);
        $this->assertSame(strlen($first_group . $first_marker), $first['byte_offset']);
        $this->assertSame($first_cursor, $first['exporter_cursor']);

        $second = $read_group->invoke($client, $handle);
        $this->assertSame($second_group, $second['sql']);
        $this->assertSame(
            strlen($first_group . $first_marker . $second_group . $second_marker),
            $second['byte_offset']
        );
        $this->assertSame($second_cursor, $second['exporter_cursor']);
        $this->assertNull($read_group->invoke($client, $handle));
        fclose($handle);
    }

    public function testRefusesSqlAfterTheLastCompleteGroup(): void
    {
        $cursor = base64_encode(json_encode(['current_table' => 'wp_posts']));
        $handle = fopen('php://temp', 'w+');
        fwrite(
            $handle,
            "INSERT INTO `wp_posts` VALUES (1);\n" . self::MARKER . $cursor . "\n" .
            "INSERT INTO `wp_posts` VALUES (2)"
        );
        rewind($handle);

        $client = ( new \ReflectionClass(\ImportClient::class) )->newInstanceWithoutConstructor();
        $read_group = new \ReflectionMethod(\ImportClient::class, 'read_next_sql_group');
        $read_group->setAccessible(true);
        $read_group->invoke($client, $handle);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ends without an SQL group cursor');
        $read_group->invoke($client, $handle);
    }
}
