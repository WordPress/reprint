<?php

namespace WordPress\Reprint\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\DatabasePush;

final class DatabasePushIdentifierTest extends TestCase {
    public function testValidationDoesNotReturnQuotedSql(): void {
        foreach (['a', 'wp_Options123', str_repeat('a', 64)] as $name) {
            self::assertNull(DatabasePush::validate_identifier($name));
            self::assertSame('`' . $name . '`', DatabasePush::identifier($name));
        }
    }

    public function testValidationRejectsUnsupportedNames(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database push SQL identifier must contain 1–64 letters, digits, or underscores: wp-plugin.');
        DatabasePush::validate_identifier('wp-plugin');
    }

    public function testQuotingStillRejectsUnsupportedNames(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database push SQL identifier must contain 1–64 letters, digits, or underscores: wp-plugin.');
        DatabasePush::identifier('wp-plugin');
    }
    public function testExtraSelectionContainsOnlyExactDistinctNamesOutsidePrefix(): void {
        self::assertSame(['Plugin_rows', 'plugin_rows'], DatabasePush::normalize_extra_tables(['plugin_rows', 'wp_options', 'Plugin_rows', 'plugin_rows'], 'wp_'));
    }

    /** @dataProvider internalTableProvider */
    public function testExtraSelectionRejectsInternalTables(string $table): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot include its internal table');
        DatabasePush::normalize_extra_tables([$table], 'wp_');
    }

    public static function internalTableProvider(): array {
        return [['__reprint_db_abc_state'], ['__REPRINT_DB_PULL_PROGRESS_abc'], ['wp_reprint_users']];
    }
}
