<?php

use PHPUnit\Framework\TestCase;

final class HandbookManifestTest extends TestCase {
    public function testEveryPageIsListedAndLinksResolveToPublishedRoutes(): void
    {
        $docs_directory = __DIR__ . '/../docs';
        $manifest_file = $docs_directory . '/manifest.json';
        $this->assertFileExists($manifest_file);
        $manifest = json_decode(file_get_contents($manifest_file), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($manifest);

        $published_urls = [];
        $sources = [];
        foreach ($manifest as $key => $page) {
            $this->assertSame(['title', 'slug', 'markdown_source', 'parent', 'order'], array_keys($page));
            $this->assertMatchesRegularExpression('/^[a-z]+(?:-[a-z]+)*$/', $page['slug']);
            $this->assertIsInt($page['order']);
            $this->assertGreaterThan(0, $page['order']);

            $parent = $page['parent'];
            $contributor_page = str_starts_with($page['markdown_source'], 'contributing/');
            // The Handbook plugin includes parent slugs in child URLs. Only
            // contributor reference pages belong under another handbook page.
            $expected_parent = $contributor_page && $key !== 'contributing' ? 'contributing' : null;
            $this->assertSame($expected_parent, $parent);
            if ($parent !== null) {
                $this->assertArrayHasKey($parent, $published_urls, 'List each parent before its children.');
            }
            $published_urls[$key] = $key === 'handbook' ? 'https://developer.wordpress.org/reprint/' : ( $parent === null ? 'https://developer.wordpress.org/reprint/' : $published_urls[$parent] ) . $page['slug'] . '/';
            $source = $page['markdown_source'];
            $this->assertSame(( $contributor_page ? 'contributing/' : 'handbook/' ) . ( $key === 'handbook' ? 'README' : $page['slug'] ) . '.md', $source);
            $this->assertFileExists($docs_directory . '/' . $source);
            $this->assertArrayNotHasKey($source, $sources);
            $sources[$source] = file_get_contents($docs_directory . '/' . $source);
            $this->assertStringStartsWith('# ' . $page['title'] . "\n", $sources[$source]);
            $this->assertDoesNotMatchRegularExpression(
                '~(?<!`)`[^`\n]*https?://[^`\n]*`(?!`)~',
                $sources[$source],
                $source . ': Put example addresses in fenced code or plain text; the native Markdown parser can swallow following links after an inline-code URL.'
            );
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs_directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md' && !in_array($file->getFilename(), ['publishing.md', 'push-terminology.md'], true)) {
                $files[] = $file->getRealPath();
            }
        }
        $this->assertEqualsCanonicalizing(
            array_map('realpath', $files),
            array_map(static function (string $source) use ($docs_directory): string {
                return realpath($docs_directory . '/' . $source);
            }, array_keys($sources)),
            'Every handbook page must be listed in the import manifest.'
        );

        $navigation = [];
        foreach ($manifest as $key => $page) {
            $source = $page['markdown_source'];
            $navigation[$published_urls[$key]] = [];
            preg_match_all('/\[[^\]]+\]\(([^\s)]+)\)/', $sources[$source], $links);
            foreach ($links[1] as $link) {
                $url = parse_url($link);
                $this->assertNotFalse($url, $source . ': ' . $link);
                $this->assertSame('https', $url['scheme'] ?? null, 'Use a complete HTTPS URL, including for links within the handbook: ' . $link);
                if (str_starts_with($link, 'https://developer.wordpress.org/reprint/')) {
                    $target = explode('#', $link, 2)[0];
                    $this->assertContains($target, $published_urls, $source . ': ' . $link);
                    $navigation[$published_urls[$key]][] = $target;
                } elseif ($url['host'] === 'github.com') {
                    $this->assertMatchesRegularExpression('#^/WordPress/reprint(?:/(?:issues|tree|blob|actions/workflows)(?:/|$)|/?$)#', $url['path']);
                } elseif (!in_array($url['host'], ['reprint-demo.wordpress.net', 'dev.mysql.com', 'mariadb.com'], true)) {
                    $this->assertMatchesRegularExpression('/(?:^|\.)wordpress\.org$/', $url['host'], $link);
                }
                if ($url['host'] === 'plugins.svn.wordpress.org') {
                    $this->assertSame('https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar', $link);
                }
            }
        }

        $pending = [$published_urls['handbook']];
        $reachable = [];
        while ($pending) {
            $path = array_pop($pending);
            if (isset($reachable[$path])) {
                continue;
            }
            $reachable[$path] = true;
            $pending = array_merge($pending, $navigation[$path]);
        }
        $this->assertEqualsCanonicalizing(array_values($published_urls), array_keys($reachable), 'Every page must be reachable from the home, without forcing every link onto it.');
    }

    public function testIntegrationExampleIsValidPhp(): void
    {
        $page = file_get_contents(__DIR__ . '/../docs/handbook/build-with-reprint.md');
        $this->assertSame(1, preg_match('/```php\n(.*?)\n```/s', $page, $example));
        $this->assertNotEmpty(token_get_all($example[1], TOKEN_PARSE));
    }

    public function testExistingPublishedSlugsRemainAvailable(): void
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/../docs/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['handbook', 'copy-a-site', 'authorize-client', 'requirements', 'troubleshooting', 'how-it-works', 'build-with-reprint'] as $slug) {
            $this->assertArrayHasKey($slug, $manifest);
            $this->assertSame($slug, $manifest[$slug]['slug']);
        }
    }

    public function testCommandReferenceListsTheCurrentCliCommands(): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../packages/reprint-client/src/import.php', '--help'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__)
        );
        $this->assertIsResource($process);
        $help = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // Global help without a command currently exits 1. This test checks
        // the command inventory, not the no-command help exit policy.
        $this->assertContains(proc_close($process), [0, 1], $errors);

        preg_match_all('/^  ([a-z]+(?:-[a-z]+)*)\s{2,}/m', $help, $commands);
        $this->assertNotEmpty($commands[1]);
        $reference = file_get_contents(__DIR__ . '/../docs/handbook/commands.md');
        preg_match_all('/^\| `([a-z]+(?:-[a-z]+)*)` \|/m', $reference, $documented);
        $this->assertEqualsCanonicalizing($commands[1], $documented[1], 'Update the command index when the CLI changes.');
    }

    public function testDocumentationUsesAbsoluteUrlsAndSourceLinksResolveToFiles(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge([$root . '/README.md', $root . '/CONTRIBUTING.md'], glob($root . '/docs/*/*.md'));
        foreach ($files as $file) {
            preg_match_all('/\[[^\]]+\]\(([^\s)]+)\)/', file_get_contents($file), $links);
            foreach ($links[1] as $link) {
                $url = parse_url($link);
                $this->assertNotFalse($url, $file . ': ' . $link);
                if (preg_match('/^L[0-9]+(?:-L?[0-9]+)?$/', $url['fragment'] ?? '')) {
                    $this->assertMatchesRegularExpression(
                        '#^https://github\\.com/WordPress/reprint/blob/[0-9a-f]{40}/#',
                        $link,
                        'A source line range must link to a specific commit, not a moving branch or relative file.'
                    );
                }
                $this->assertSame('https', $url['scheme'] ?? null, 'Use a complete HTTPS URL: ' . $link);
                if (preg_match('#^https://github\\.com/WordPress/reprint/(?:blob|tree)/trunk/(.+)$#', explode('#', $link, 2)[0], $source)) {
                    $this->assertFileExists($root . '/' . rawurldecode($source[1]), $file . ': ' . $link);
                } elseif (preg_match('#^https://github\\.com/WordPress/reprint/actions/workflows/(.+)$#', $link, $workflow)) {
                    $this->assertFileExists($root . '/.github/workflows/' . $workflow[1], $file . ': ' . $link);
                }
            }
        }

        preg_match_all('/(?:src|srcset)="([^"]+)"/', file_get_contents($root . '/README.md'), $images);
        foreach ($images[1] as $image) {
            $this->assertStringStartsWith('https://', $image, 'The README logo must work when shown outside GitHub.');
        }
    }
}
