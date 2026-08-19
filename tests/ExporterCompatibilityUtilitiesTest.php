<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Server\generate_random_bytes;
use function WordPress\Reprint\Server\integer_divide;

final class ExporterCompatibilityUtilitiesTest extends TestCase
{
    public function testRandomByteFallbackContract(): void
    {
        $bytes = generate_random_bytes(32);

        $this->assertSame(32, strlen($bytes));
    }

    public function testIntegerDivisionRoundsTowardZero(): void
    {
        $this->assertSame(2, integer_divide(7, 3));
        $this->assertSame(-2, integer_divide(-7, 3));
    }
}
