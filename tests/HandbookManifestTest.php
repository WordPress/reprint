<?php

use PHPUnit\Framework\TestCase;

final class HandbookManifestTest extends TestCase {
    public function testEveryPageIsListedAndLinksResolveToFiles(): void
    {
        $docs_directory = __DIR__ . '/../docs';
        $manifest_file = $docs_directory . '/manifest.json';
        $this->assertFileExists($manifest_file);
        $manifest = json_decode(file_get_contents($manifest_file), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($manifest);

        $source_by_key = [];
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
                $this->assertArrayHasKey($parent, $source_by_key, 'List each parent before its children.');
            }
            $source = $page['markdown_source'];
            $source_by_key[$key] = $source;
            $this->assertSame(( $contributor_page ? 'contributing/' : 'handbook/' ) . ( $key === 'handbook' ? 'README' : $page['slug'] ) . '.md', $source);
            $this->assertFileExists($docs_directory . '/' . $source);
            $this->assertArrayNotHasKey($source, $sources);
            $sources[$source] = file_get_contents($docs_directory . '/' . $source);
            $this->assertStringStartsWith('# ' . $page['title'] . "\n", $sources[$source]);
            $this->assertStringNotContainsString('vendor/bin/reprint-client', $sources[$source], 'Run php reprint.phar in handbook examples, including contributor pages.');
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
        foreach ($manifest as $page) {
            $source = $page['markdown_source'];
            $navigation[$source] = [];
            preg_match_all('/\[[^\]]+\]\(([^\s)]+)\)/', $sources[$source], $links);
            foreach ($links[1] as $link) {
                $url = parse_url($link);
                $this->assertNotFalse($url, $source . ': ' . $link);
                $this->assertStringNotContainsString('https://developer.wordpress.org/reprint/', $link, 'Link to the Markdown file until the handbook is live: ' . $link);
                if (!isset($url['scheme']) && !isset($url['host'])) {
                    $target = empty($url['path']) ? $docs_directory . '/' . $source : dirname($docs_directory . '/' . $source) . '/' . rawurldecode($url['path']);
                    $this->assertFileExists($target, $source . ': ' . $link);
                    $target = substr(realpath($target), strlen(realpath($docs_directory)) + 1);
                    if (isset($sources[$target])) {
                        $navigation[$source][] = $target;
                    }
                    continue;
                }
                $this->assertSame('https', $url['scheme'] ?? null, $link);
                if ($url['host'] === 'github.com') {
                    $this->assertMatchesRegularExpression('#^/WordPress/reprint(?:/(?:issues|tree|blob|actions/workflows)(?:/|$)|/?$)#', $url['path']);
                } elseif (!in_array($url['host'], ['reprint-demo.wordpress.net', 'dev.mysql.com', 'mariadb.com'], true)) {
                    $this->assertMatchesRegularExpression('/(?:^|\.)wordpress\.org$/', $url['host'], $link);
                }
                if ($url['host'] === 'plugins.svn.wordpress.org') {
                    $this->assertSame('https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar', $link);
                }
            }
        }

        $pending = [$source_by_key['handbook']];
        $reachable = [];
        while ($pending) {
            $path = array_pop($pending);
            if (isset($reachable[$path])) {
                continue;
            }
            $reachable[$path] = true;
            $pending = array_merge($pending, $navigation[$path]);
        }
        $this->assertEqualsCanonicalizing(array_values($source_by_key), array_keys($reachable), 'Every page must be reachable from the home, without forcing every link onto it.');
    }

    public function testIntegrationExampleIsValidPhp(): void
    {
        $page = file_get_contents(__DIR__ . '/../docs/handbook/build-with-reprint.md');
        $this->assertSame(1, preg_match('/```php\n(.*?)\n```/s', $page, $example));
        $this->assertNotEmpty(token_get_all($example[1], TOKEN_PARSE));
        $this->assertStringContainsString('--private-key-path=', $example[1]);
    }

    public function testRemoteCommandExamplesNameTheirCredentials(): void
    {
        require_once __DIR__ . '/../packages/reprint-client/src/import.php';

        $root = dirname(__DIR__);
        $files = array_merge([$root . '/README.md'], glob($root . '/docs/*/*.md'));
        $remote_commands = array_merge(ImportClient::REMOTE_COMMANDS, ['files-push', 'db-push']);
        foreach ($files as $file) {
            preg_match_all('/```(?:sh|bash)\n(.*?)\n```/s', file_get_contents($file), $blocks);
            foreach ($blocks[1] as $block) {
                $block = str_replace("\\\n", ' ', $block);
                preg_match_all('/^php reprint\.phar ([a-z]+(?:-[a-z]+)*)\b([^\n]*)/m', $block, $commands, PREG_SET_ORDER);
                foreach ($commands as $command) {
                    if (!in_array($command[1], $remote_commands, true) || preg_match('/--(?:help|abort)\b/', $command[2])) {
                        continue;
                    }
                    $this->assertMatchesRegularExpression('/--(?:private-key-path|secret)=\S+/', $command[2], $file . ': ' . $command[0]);
                    $this->assertFalse(
                        str_contains($command[2], '--private-key-path=') && str_contains($command[2], '--secret='),
                        'Use one authorization method: ' . $command[0]
                    );
                    $this->assertStringContainsString('--state-dir=', $command[2], $file . ': ' . $command[0]);
                    if ($command[1] !== 'db-push') {
                        $this->assertStringContainsString('--fs-root=', $command[2], $file . ': ' . $command[0]);
                    }
                }
            }
        }
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

    public function testDocumentationLinksResolveToFiles(): void
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
                if (!isset($url['scheme']) && !isset($url['host'])) {
                    $target = empty($url['path']) ? $file : dirname($file) . '/' . rawurldecode($url['path']);
                    $this->assertFileExists($target, $file . ': ' . $link);
                    continue;
                }
                $this->assertStringNotContainsString('https://developer.wordpress.org/reprint/', $link, 'Link to repository files until the handbook is live.');
                $this->assertSame('https', $url['scheme'] ?? null, 'External links use HTTPS: ' . $link);
                if (preg_match('#^https://github\\.com/WordPress/reprint/(?:blob|tree)/trunk/(.+)$#', explode('#', $link, 2)[0], $source)) {
                    $this->assertFileExists($root . '/' . rawurldecode($source[1]), $file . ': ' . $link);
                } elseif (preg_match('#^https://github\\.com/WordPress/reprint/actions/workflows/(.+)$#', $link, $workflow)) {
                    $this->assertFileExists($root . '/.github/workflows/' . $workflow[1], $file . ': ' . $link);
                }
            }
        }

        $readme = file_get_contents($root . '/README.md');
        $this->assertStringNotContainsString('https://developer.wordpress.org/reprint/', $readme, 'README links must open documentation in the current GitHub branch, including before website publication.');
        preg_match_all('/(?:src|srcset)="([^"]+)"/', $readme, $images);
        foreach ($images[1] as $image) {
            $this->assertFileExists($root . '/' . $image, 'The README logo must exist in the current GitHub branch.');
        }
    }
}
