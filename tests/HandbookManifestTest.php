<?php

use PHPUnit\Framework\TestCase;

final class HandbookManifestTest extends TestCase {
    public function testEveryPageIsListedAndLinksResolveToHandbookUrls(): void
    {
        $docs_directory = __DIR__ . '/../docs';
        $manifest_file = $docs_directory . '/manifest.json';
        $this->assertFileExists($manifest_file);
        $manifest = json_decode(file_get_contents($manifest_file), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($manifest);

        $chapter_groups = ['getting-started', 'guides', 'build-with-reprint', 'reference', 'understand-reprint', 'contributing'];
        $root_pages = [];
        $children_by_parent = [];
        $source_by_key = [];
        $sources = [];
        foreach ($manifest as $key => $page) {
            $this->assertSame(['title', 'slug', 'markdown_source', 'parent', 'order'], array_keys($page));
            $this->assertMatchesRegularExpression('/^[a-z]+(?:-[a-z]+)*$/', $page['slug']);
            $this->assertIsInt($page['order']);
            $this->assertGreaterThan(0, $page['order']);

            $parent = $page['parent'];
            $contributor_page = str_starts_with($page['markdown_source'], 'contributing/');
            // The Handbook plugin uses page parents for both Chapters groups
            // and child URLs. Keep content pages under the six section pages.
            if ($key === 'handbook' || in_array($key, $chapter_groups, true)) {
                $this->assertNull($parent);
                $root_pages[] = $key;
            } elseif ($contributor_page) {
                $this->assertSame('contributing', $parent);
            } else {
                $this->assertContains($parent, $chapter_groups, $key . ': Every content page needs a chapter group.');
            }
            if ($parent !== null) {
                $this->assertArrayHasKey($parent, $source_by_key, 'List each parent before its children.');
                $children_by_parent[$parent][] = $key;
            }
            $source = $page['markdown_source'];
            $source_by_key[$key] = $source;
            $this->assertSame(( $contributor_page ? 'contributing/' : 'handbook/' ) . ( $key === 'handbook' ? 'README' : $page['slug'] ) . '.md', $source);
            $this->assertFileExists($docs_directory . '/' . $source);
            $this->assertArrayNotHasKey($source, $sources);
            $sources[$source] = file_get_contents($docs_directory . '/' . $source);
            $this->assertStringStartsWith('# ' . $page['title'] . "\n", $sources[$source]);
            $this->assertStringNotContainsString('vendor/bin/reprint-client', $sources[$source], 'Run php reprint.phar in handbook examples, including contributor pages.');
            preg_match_all('~(?<!`)`[^`\n]*`(?!`)~', $sources[$source], $inline_code);
            $this->assertDoesNotMatchRegularExpression(
                '~https?://~',
                implode("\n", $inline_code[0]),
                $source . ': Put example addresses in fenced code or plain text; the native Markdown parser can swallow following links after an inline-code URL.'
            );
        }
        $this->assertSame(array_merge(['handbook'], $chapter_groups), $root_pages, 'Keep the chapter list to the handbook home and six sections.');
        foreach ($chapter_groups as $group) {
            $this->assertNotEmpty($children_by_parent[$group] ?? [], $group . ': Each chapter group needs child pages.');
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

        $source_by_url = $this->handbookSourcesByUrl();
        $navigation = [];
        foreach ($manifest as $page) {
            $source = $page['markdown_source'];
            $navigation[$source] = [];
            preg_match_all('/\[[^\]]+\]\(([^\s)]+)\)/', $sources[$source], $links);
            foreach ($links[1] as $link) {
                $url = parse_url($link);
                $this->assertNotFalse($url, $source . ': ' . $link);
                if (!isset($url['scheme']) && !isset($url['host'])) {
                    $this->assertEmpty($url['path'] ?? '', $source . ': Use the handbook URL for a page link: ' . $link);
                    continue;
                }
                $this->assertSame('https', $url['scheme'] ?? null, $link);
                if ($url['host'] === 'developer.wordpress.org' && preg_match('#^/reprint(?:/|$)#', $url['path'] ?? '')) {
                    $target_url = explode('#', $link, 2)[0];
                    $this->assertArrayHasKey($target_url, $source_by_url, $source . ': ' . $link);
                    $navigation[$source][] = $source_by_url[$target_url];
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

    public function testRuntimePullOptionsKeepTheirLinkedHeadingId(): void
    {
        // The Handbook TOC leaves a one-heading body unchanged. Keep the ID
        // in the source so links from other pages still reach this section.
        $runtime = file_get_contents(__DIR__ . '/../docs/handbook/runtime.md');
        $this->assertStringContainsString('<h2 id="runtime-options-on-pull">', $runtime);
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

    public function testHandbookUrlsMatchTheLandingPageAndChapterHierarchy(): void
    {
        $source_by_url = $this->handbookSourcesByUrl();
        $this->assertSame('handbook/README.md', $source_by_url['https://developer.wordpress.org/reprint/']);
        $this->assertSame('handbook/copy-a-site.md', $source_by_url['https://developer.wordpress.org/reprint/getting-started/copy-a-site/']);
        $this->assertSame('handbook/commands.md', $source_by_url['https://developer.wordpress.org/reprint/reference/commands/']);
        $this->assertSame('handbook/integration-pipeline.md', $source_by_url['https://developer.wordpress.org/reprint/build-with-reprint/integration-pipeline/']);
        $this->assertSame('contributing/architecture.md', $source_by_url['https://developer.wordpress.org/reprint/contributing/architecture/']);
    }

    public function testDocumentationLinksResolveToSources(): void
    {
        $root = dirname(__DIR__);
        $source_by_url = $this->handbookSourcesByUrl();
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
                $this->assertSame('https', $url['scheme'] ?? null, 'External links use HTTPS: ' . $link);
                if ($url['host'] === 'developer.wordpress.org' && preg_match('#^/reprint(?:/|$)#', $url['path'] ?? '')) {
                    $this->assertArrayHasKey(explode('#', $link, 2)[0], $source_by_url, $file . ': ' . $link);
                } elseif (preg_match('#^https://github\\.com/WordPress/reprint/(?:blob|tree)/trunk/(.+)$#', explode('#', $link, 2)[0], $source)) {
                    $this->assertFileExists($root . '/' . rawurldecode($source[1]), $file . ': ' . $link);
                } elseif (preg_match('#^https://github\\.com/WordPress/reprint/actions/workflows/(.+)$#', $link, $workflow)) {
                    $this->assertFileExists($root . '/.github/workflows/' . $workflow[1], $file . ': ' . $link);
                }
            }
        }

        $readme = file_get_contents($root . '/README.md');
        $this->assertStringNotContainsString('https://developer.wordpress.org/reprint/', $readme, 'README links must open documentation in the current GitHub branch, including before website publication.');
        $this->assertStringNotContainsString('https://developer.wordpress.org/reprint/', file_get_contents($root . '/CONTRIBUTING.md'), 'CONTRIBUTING links must open documentation in the current GitHub branch.');
        preg_match_all('/(?:src|srcset)="([^"]+)"/', $readme, $images);
        foreach ($images[1] as $image) {
            $this->assertFileExists($root . '/' . $image, 'The README logo must exist in the current GitHub branch.');
        }
    }

    private function handbookSourcesByUrl(): array
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/../docs/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $url_by_key = [];
        $source_by_url = [];
        foreach ($manifest as $key => $page) {
            $parent_url = $page['parent'] === null ? 'https://developer.wordpress.org/reprint/' : $url_by_key[$page['parent']];
            $url = $parent_url . ( $key === 'handbook' ? '' : $page['slug'] . '/' );
            $this->assertArrayNotHasKey($url, $source_by_url, 'Each handbook page must have a unique URL.');
            $url_by_key[$key] = $url;
            $source_by_url[$url] = $page['markdown_source'];
        }
        return $source_by_url;
    }
}
