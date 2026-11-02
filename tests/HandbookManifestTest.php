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

        $published_paths = [];
        $sources = [];
        foreach ($manifest as $key => $page) {
            $this->assertSame(['title', 'slug', 'markdown_source', 'parent', 'order'], array_keys($page));
            $this->assertMatchesRegularExpression('/^[a-z]+(?:-[a-z]+)*$/', $page['slug']);
            $this->assertIsInt($page['order']);
            $this->assertGreaterThan(0, $page['order']);

            $parent = $page['parent'];
            $this->assertSame($key === 'handbook' ? null : 'handbook', $parent, 'Keep the published routes flat while grouping navigation on the home page.');
            if ($parent !== null) {
                $this->assertArrayHasKey($parent, $published_paths, 'List each parent before its children.');
            }
            $published_paths[$key] = ( $parent === null ? '' : $published_paths[$parent] . '/' ) . $page['slug'];
            $source = $page['markdown_source'];
            $this->assertSame('handbook/' . ( $parent === null ? 'README' : $page['slug'] ) . '.md', $source);
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
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs_directory . '/handbook'));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
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
            $navigation[$published_paths[$key]] = [];
            preg_match_all('/\[[^\]]+\]\(([^\s)]+)\)/', $sources[$source], $links);
            foreach ($links[1] as $link) {
                $url = parse_url($link);
                $this->assertNotFalse($url, $source . ': ' . $link);
                if (isset($url['host'])) {
                    $this->assertSame('https', $url['scheme'], $link);
                    if ($url['host'] === 'github.com') {
                        $this->assertMatchesRegularExpression('#^/WordPress/reprint(?:/(?:issues|tree|blob)(?:/|$)|/?$)#', $url['path']);
                    } elseif ($url['host'] !== 'reprint-demo.wordpress.net') {
                        $this->assertMatchesRegularExpression('/(?:^|\.)wordpress\.org$/', $url['host'], $link);
                    }
                    if ($url['host'] === 'plugins.svn.wordpress.org') {
                        $this->assertSame('https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar', $link);
                    }
                    continue;
                }

                $this->assertStringEndsWith('/', $url['path'], 'Link to published handbook pages, not Markdown files: ' . $link);
                $this->assertNotSame('/', substr($url['path'], 0, 1), 'Keep links relative to the handbook: ' . $link);
                $target = [];
                foreach (explode('/', $published_paths[$key] . '/' . $url['path']) as $part) {
                    if ($part === '..') {
                        array_pop($target);
                    } elseif ($part !== '' && $part !== '.') {
                        $target[] = $part;
                    }
                }
                $target = implode('/', $target);
                $this->assertContains($target, $published_paths, $source . ': ' . $link);
                $navigation[$published_paths[$key]][] = $target;
            }
        }

        $pending = [$published_paths['handbook']];
        $reachable = [];
        while ($pending) {
            $path = array_pop($pending);
            if (isset($reachable[$path])) {
                continue;
            }
            $reachable[$path] = true;
            $pending = array_merge($pending, $navigation[$path]);
        }
        $this->assertEqualsCanonicalizing(array_values($published_paths), array_keys($reachable), 'Every page must be reachable from the home, without forcing every link onto it.');
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

    public function testRepositoryDocumentationLinksResolveToFiles(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge([$root . '/README.md', $root . '/CONTRIBUTING.md'], glob($root . '/docs/contributing/*.md'));
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
                if (isset($url['host']) || empty($url['path'])) {
                    continue;
                }
                $this->assertFileExists(dirname($file) . '/' . rawurldecode($url['path']), $file . ': ' . $link);
            }
        }
    }
}
