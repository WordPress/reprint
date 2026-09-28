<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\Utils;

final class ExporterCompatibilityUtilitiesTest extends TestCase
{
    public function testRandomByteFallbackContract(): void
    {
        $bytes = Utils::generate_random_bytes(32);

        $this->assertSame(32, strlen($bytes));
    }

    public function testConnectionDsnKeepsEscapedSemicolonsAndEqualsInValues(): void
    {
        $this->assertSame(
            ['path' => '/tmp/local;site=one.sqlite', 'dbname' => 'local'],
            Utils::parse_pdo_dsn('mysql-on-sqlite:path=/tmp/local;;site=one.sqlite;dbname=local')
        );
        $this->assertSame(
            ['host' => '127.0.0.1', 'port' => '3307', 'dbname' => 'local'],
            Utils::parse_pdo_dsn('mysql:host=127.0.0.1; port=3307;dbname=local')
        );
    }

    public function testIntegerDivisionRoundsTowardZero(): void
    {
        $this->assertSame(2, Utils::integer_divide(7, 3));
        $this->assertSame(-2, Utils::integer_divide(-7, 3));
    }
}
