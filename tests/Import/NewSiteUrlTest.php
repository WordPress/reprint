<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Test the --new-site-url flag, which derives --rewrite-url mappings
 * for both HTTP and HTTPS variants of the export URL's origin.
 */
class NewSiteUrlTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-new-site-url-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
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
        $items = scandir($dir);
        foreach ($items as $item) {
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

    private function callResolve(\ImportClient $client, array $options): array
    {
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolve_new_site_url_option');
        $method->invokeArgs($client, [&$options]);
        return $options;
    }

    /** A selected subsite moves without rewriting the old network's home page. */
    public function testMultisiteUsesSelectedSiteAndAssetUrls(): void
    {
        $client = new \ImportClient('https://network.test/shop/?reprint-api', $this->tempDir, $this->tempDir . '/fs-root');
        $client->get_state()->set_preflight_record(['data' => ['database' => ['wp' => ['multisite' => [
            'enabled' => true,
            'selection' => [
                'site_id' => 7, 'network_id' => 1, 'base_prefix' => 'wp_',
                'home_url' => 'https://network.test/shop', 'site_url' => 'https://network.test/shop',
                'content_url' => 'https://network.test/wp-content',
                'uploads_url' => 'https://network.test/wp-content/uploads/sites/7',
            ],
        ]]]]]);
        $options = $this->callResolve($client, ['new_site_url' => 'http://localhost:9000', 'network_admin' => 'chosen']);
        $mapping = array_column($options['rewrite_url'], 1, 0);
        $this->assertArrayNotHasKey('https://network.test', $mapping);
        $this->assertSame('http://localhost:9000', $mapping['https://network.test/shop']);
    }

    public function testDerivesHttpAndHttpsFromHttpsUrl(): void
    {
        $client = new \ImportClient(
            'https://old-site.example.com/wp-json/export',
            $this->tempDir,
            $this->tempDir . '/fs-root'
        );

        $options = ['new_site_url' => 'https://new-site.example.com'];
        $options = $this->callResolve($client, $options);

        $this->assertArrayHasKey('rewrite_url', $options);
        $this->assertCount(2, $options['rewrite_url']);
        $this->assertSame(
            ['https://old-site.example.com', 'https://new-site.example.com'],
            $options['rewrite_url'][0]
        );
        $this->assertSame(
            ['http://old-site.example.com', 'https://new-site.example.com'],
            $options['rewrite_url'][1]
        );
    }

    public function testDerivesHttpAndHttpsFromHttpUrl(): void
    {
        $client = new \ImportClient(
            'http://old-site.local/export?key=abc',
            $this->tempDir,
            $this->tempDir . '/fs-root'
        );

        $options = ['new_site_url' => 'https://new-site.example.com'];
        $options = $this->callResolve($client, $options);

        $this->assertCount(2, $options['rewrite_url']);
        $this->assertSame(
            ['https://old-site.local', 'https://new-site.example.com'],
            $options['rewrite_url'][0]
        );
        $this->assertSame(
            ['http://old-site.local', 'https://new-site.example.com'],
            $options['rewrite_url'][1]
        );
    }

    public function testPreservesPort(): void
    {
        $client = new \ImportClient(
            'https://old-site.example.com:8443/export',
            $this->tempDir,
            $this->tempDir . '/fs-root'
        );

        $options = ['new_site_url' => 'https://new-site.example.com'];
        $options = $this->callResolve($client, $options);

        $this->assertCount(2, $options['rewrite_url']);
        $this->assertSame(
            ['https://old-site.example.com:8443', 'https://new-site.example.com'],
            $options['rewrite_url'][0]
        );
        $this->assertSame(
            ['http://old-site.example.com:8443', 'https://new-site.example.com'],
            $options['rewrite_url'][1]
        );
    }

    public function testAppendsToExistingRewriteUrlEntries(): void
    {
        $client = new \ImportClient(
            'https://old-site.example.com/export',
            $this->tempDir,
            $this->tempDir . '/fs-root'
        );

        $options = [
            'new_site_url' => 'https://new-site.example.com',
            'rewrite_url' => [
                ['https://cdn.old-site.com', 'https://cdn.new-site.com'],
            ],
        ];
        $options = $this->callResolve($client, $options);

        $this->assertCount(3, $options['rewrite_url']);
        $this->assertSame(
            ['https://cdn.old-site.com', 'https://cdn.new-site.com'],
            $options['rewrite_url'][0]
        );
        $this->assertSame(
            ['https://old-site.example.com', 'https://new-site.example.com'],
            $options['rewrite_url'][1]
        );
        $this->assertSame(
            ['http://old-site.example.com', 'https://new-site.example.com'],
            $options['rewrite_url'][2]
        );
    }

    public function testNoOpWhenNewSiteUrlNotSet(): void
    {
        $client = new \ImportClient(
            'https://old-site.example.com/export',
            $this->tempDir,
            $this->tempDir . '/fs-root'
        );

        $options = [];
        $options = $this->callResolve($client, $options);

        $this->assertArrayNotHasKey('rewrite_url', $options);
    }

    public function testThrowsOnUnparseableExportUrl(): void
    {
        $client = new \ImportClient(
            '://not-a-url',
            $this->tempDir,
            $this->tempDir . '/fs-root'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--new-site-url requires a valid export URL');

        $options = ['new_site_url' => 'https://new-site.example.com'];
        $this->callResolve($client, $options);
    }

    public function testRequiresAnExplicitMappingWithoutARemoteUrl(): void
    {
        $client = new \ImportClient(
            '',
            $this->tempDir,
            $this->tempDir . '/fs-root'
        );
        write_current_pull_state($client, [
            'preflight' => [
                'data' => [
                    'database' => [
                        'wp' => [
                            'paths_urls' => [
                                'home_url' => 'https://saved-source.example:8443/wordpress',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            '--new-site-url requires a positional remote Reprint API URL. ' .
            'Use --rewrite-url FROM TO when no remote URL is available.'
        );

        $this->callResolve($client, [
            'new_site_url' => 'https://new-site.example.com',
        ]);
    }

    public function testNewUrlUsedVerbatim(): void
    {
        $client = new \ImportClient(
            'https://old-site.example.com/export',
            $this->tempDir,
            $this->tempDir . '/fs-root'
        );

        // The new URL should be used exactly as-is, even with a trailing path
        $options = ['new_site_url' => 'http://localhost:8080/subdir'];
        $options = $this->callResolve($client, $options);

        $this->assertCount(2, $options['rewrite_url']);
        // Both variants map to the exact same verbatim new URL
        $this->assertSame('http://localhost:8080/subdir', $options['rewrite_url'][0][1]);
        $this->assertSame('http://localhost:8080/subdir', $options['rewrite_url'][1][1]);
    }
}
