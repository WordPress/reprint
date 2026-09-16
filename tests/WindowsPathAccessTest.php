<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\HTTPServer;

final class WindowsPathAccessTest extends TestCase
{
    public function testUnixSourceAccessPreservesWindowsLookingNames(): void
    {
        if (PHP_OS === 'WINNT') {
            $this->markTestSkipped('Unix filename preservation requires a Unix host.');
        }
        foreach (['/site/workspace\\group\\user/www', '/site/report.', '/site/report ', '/site/D:\\report'] as $path) {
            $this->assertSame($path, \WordPress\Reprint\Server\source_io_path($path));
        }
    }
}
