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

        foreach ($manifest as $key => $page) {
            $source = $page['markdown_source'];
            preg_match_all('/\[[^\]]+\]\(([^\s)]+)\)/', $sources[$source], $links);
            foreach ($links[1] as $link) {
                $url = parse_url($link);
                $this->assertNotFalse($url, $source . ': ' . $link);
                if (isset($url['host'])) {
                    $this->assertSame('https', $url['scheme'], $link);
                    if ($url['host'] === 'github.com') {
                        $this->assertMatchesRegularExpression('#^/WordPress/reprint(?:/(?:issues|tree|blob)(?:/|$)|/?$)#', $url['path']);
                    } else {
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
                $this->assertContains(implode('/', $target), $published_paths, $source . ': ' . $link);
            }
        }
    }
}
