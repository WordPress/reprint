<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/../../client/import.php';

/**
 * Verify that HTTP requests never advertise brotli (br) encoding.
 *
 * Not every curl build ships with brotli support. When the client sends
 * "Accept-Encoding: …, br" but curl can't decode it, the transfer fails
 * with "Unrecognized content encoding type."  We must only advertise
 * encodings that every curl build supports: gzip and deflate.
 */
class AcceptEncodingTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-encoding-test-' . uniqid();
        mkdir($this->tempDir . '/state', 0755, true);
        mkdir($this->tempDir . '/fs-root', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * The Accept-Encoding header must not include "br" (brotli),
     * because curl may not have brotli support compiled in.
     */
    public function testAcceptEncodingExcludesBrotli(): void
    {
        $client = new \ImportClient(
            'http://fake.url',
            $this->tempDir . '/state',
            $this->tempDir . '/fs-root'
        );

        $method = new ReflectionMethod($client, 'get_base_headers');
        $method->setAccessible(true);

        $headers = $method->invoke($client, 'application/json');

        $encoding_header = null;
        foreach ($headers as $header) {
            if (stripos($header, 'Accept-Encoding:') === 0) {
                $encoding_header = $header;
                break;
            }
        }

        $this->assertNotNull($encoding_header, 'Accept-Encoding header must be present');
        $this->assertStringNotContainsString(
            'br',
            $encoding_header,
            'Accept-Encoding must not include brotli (br) — curl may lack brotli support'
        );
        $this->assertStringContainsString('gzip', $encoding_header);
        $this->assertStringContainsString('deflate', $encoding_header);
    }
}
