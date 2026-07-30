<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Smoke test: command aliases must continue to work through the alias table.
 */
class CommandAliasTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-alias-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->filesystem_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->stateDir . '/pull', 0755, true);
        mkdir($this->filesystem_root, 0755, true);
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
     * Command aliases must be accepted by run() without throwing
     * "Invalid command". We can't actually complete the pull (no server),
     * but we verify the command is recognized and dispatched.
     *
     * @dataProvider commandAliasProvider
     */
    public function testCommandAliasIsAccepted(string $alias, string $canonical_name): void
    {
        $client = new \ImportClient('http://fake.invalid', $this->stateDir, $this->filesystem_root);

        // Write a preflight so commands that require it don't bail early.
        file_put_contents(
            $this->stateDir . '/pull/state.json',
            json_encode([
                "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            ]),
        );

        try {
            $client->run(["command" => $alias]);
        } catch (\Exception $e) {
            // Expected: network errors, missing preflight fields, etc.
            // The key assertion is that we did NOT get "Invalid command".
            $this->assertStringNotContainsString(
                "Invalid command",
                $e->getMessage(),
                "Command alias '{$alias}' should be accepted, not rejected as invalid",
            );
            return;
        }

        // If it somehow succeeded (unlikely with fake URL), that's fine too.
        $this->assertTrue(true);
    }

    public static function commandAliasProvider(): array
    {
        return [
            'files-sync → files-pull' => ['files-sync', 'files-pull'],
            'db-sync → db-pull' => ['db-sync', 'db-pull'],
            'flat-document-root → flat-docroot' => ['flat-document-root', 'flat-docroot'],
            'flatten-docroot → flat-docroot' => ['flatten-docroot', 'flat-docroot'],
        ];
    }

    public function testRetiredStateShapeIsRejected(): void
    {
        file_put_contents(
            $this->stateDir . '/pull/state.json',
            json_encode([
                "command" => "files-pull",
                "status" => "in_progress",
                "cursor" => "legacy-cursor",
                "stage" => "fetch",
                "pull" => [
                    "pipeline" => "pull",
                    "stage" => "files-pull",
                ],
            ]),
        );

        $client = new \ImportClient('http://fake.invalid', $this->stateDir, $this->filesystem_root);
        $reflection = new \ReflectionClass($client);
        $loadState = $reflection->getMethod('load_state');
        $loadState->setAccessible(true);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('does not match the current state schema');

        $loadState->invoke($client);
    }

}
