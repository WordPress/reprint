<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\PdoConstants;

final class PdoConstantsTest extends TestCase {
    public function testMatchesNativePdoConstants(): void
    {
        $this->assertSame(PDO::FETCH_ASSOC, PdoConstants::fetch_assoc());
        $this->assertSame(PDO::FETCH_COLUMN, PdoConstants::fetch_column());
        $this->assertSame(PDO::PARAM_STR, PdoConstants::param_str());
    }
}
