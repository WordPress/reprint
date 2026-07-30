<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-path-mapping.php';

final class PushPathMappingTest extends TestCase
{
    private string $tempDirectory;
    private string $localTree;
    private string $mappingFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDirectory =
            sys_get_temp_dir() . '/push-path-mapping-' . uniqid();
        $this->localTree = $this->tempDirectory . '/local-tree';
        $this->mappingFile = $this->tempDirectory . '/path-mapping.json';
        mkdir($this->localTree, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDirectory);
        parent::tearDown();
    }

    public function testMapsLocalPathsBackToTheirTargetCoordinates(): void
    {
        $mapping = $this->writeMapping([
            ['/var/www/html', $this->localTree, 'default'],
            [
                '/var/www/html/wp-content/plugins',
                $this->localTree . '/plugins',
                'remap',
            ],
        ]);

        $this->assertSame(
            'wp-content/plugins/example/plugin.php',
            $mapping->local_path_to_target_path(
                'plugins/example/plugin.php'
            )
        );
        $this->assertSame(
            'index.php',
            $mapping->local_path_to_target_path('index.php')
        );
        $this->assertSame(
            'wp-content/plugins/example\\plugin.php',
            $mapping->local_path_to_target_path(
                'plugins/example\\plugin.php'
            )
        );
    }

    public function testRewritesAPulledSymlinkTargetIntoTargetCoordinates(): void
    {
        $mapping = $this->writeMapping([
            ['/var/www/html', $this->localTree, 'default'],
            [
                '/var/www/html/wp-content/plugins',
                $this->localTree . '/plugins',
                'remap',
            ],
        ]);

        $this->assertSame(
            '../../themes/example',
            $mapping->local_symlink_target_to_target(
                'plugins/current',
                '../themes/example'
            )
        );
    }

    public function testRewritesASymlinkTargetingTheLocalTreeRoot(): void
    {
        $mapping = $this->writeMapping([
            ['/var/www/html', $this->localTree, 'default'],
            [
                '/var/www/html/wp-content/plugins',
                $this->localTree . '/plugins',
                'remap',
            ],
        ]);

        $this->assertSame(
            '../../..',
            $mapping->local_symlink_target_to_target(
                'plugins/example/current',
                '../..'
            )
        );
    }

    public function testRejectsARelativeTargetOutsideTheRemappedLocalTree(): void
    {
        $mapping = $this->writeMapping([
            ['/var/www/html', $this->localTree, 'default'],
            [
                '/var/www/html/wp-content/plugins',
                $this->localTree . '/plugins',
                'remap',
            ],
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'has a relative target outside the local tree'
        );

        $mapping->local_symlink_target_to_target(
            'plugins/current',
            '../../outside'
        );
    }

    public function testRejectsARemotePrefixOutsideTheTargetDocumentRoot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the target document root');

        $this->writeMapping([
            ['/var/www/html', $this->localTree, 'default'],
            ['/opt/plugins', $this->localTree . '/plugins', 'remap'],
        ]);
    }

    public function testRejectsOneLocalPrefixForTwoRemotePrefixes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'maps to more than one remote prefix'
        );

        $this->writeMapping([
            ['/var/www/html', $this->localTree, 'default'],
            [
                '/var/www/html/wp-content/plugins',
                $this->localTree . '/plugins',
                'remap',
            ],
            [
                '/var/www/html/wp-content/mu-plugins',
                $this->localTree . '/plugins',
                'remap',
            ],
        ]);
    }

    public function testRejectsAMappingWhichDoesNotCoverTheLocalTree(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not cover the local tree');

        $this->writeMapping([
            [
                '/var/www/html/wp-content/plugins',
                $this->localTree . '/plugins',
                'remap',
            ],
        ]);
    }

    public function testRejectsALocalPrefixWhichTraversesASymlink(): void
    {
        mkdir($this->localTree . '/real-plugins');
        symlink(
            $this->localTree . '/real-plugins',
            $this->localTree . '/plugins'
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('traverses the symlink');

        $this->writeMapping([
            ['/var/www/html', $this->localTree, 'default'],
            [
                '/var/www/html/wp-content/plugins',
                $this->localTree . '/plugins',
                'remap',
            ],
        ]);
    }

    /**
     * @param list<array{string,string,'default'|'remap'}> $rules
     */
    private function writeMapping(array $rules): PushPathMapping
    {
        $canonicalLocalTree = realpath($this->localTree);
        $this->assertIsString($canonicalLocalTree);
        $prefixRules = [];
        foreach ($rules as [$remotePrefix, $localPrefix, $kind]) {
            $prefixRules[] = [
                'kind' => $kind,
                'remote_prefix_b64' => base64_encode($remotePrefix),
                'local_prefix_b64' => base64_encode(
                    str_replace($this->localTree, $canonicalLocalTree, $localPrefix)
                ),
            ];
        }
        file_put_contents(
            $this->mappingFile,
            json_encode([
                'target_url_fingerprint' => str_repeat('1', 64),
                'filesystem_root_b64' => base64_encode($canonicalLocalTree),
                'local_tree_b64' => base64_encode($canonicalLocalTree),
                'target_document_root_b64' =>
                    base64_encode('/var/www/html'),
                'prefix_rules' => $prefixRules,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        return PushPathMapping::from_file(
            $this->mappingFile,
            $this->localTree
        );
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
