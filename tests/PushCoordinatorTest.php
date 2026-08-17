<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-server/src/class-push-exception.php';
require_once __DIR__ . '/../packages/reprint-server/src/class-push-session.php';
require_once __DIR__ . '/../packages/reprint-server/src/class-push-coordinator.php';

final class PushCoordinatorTest extends TestCase {

    private string $root;
    private string $docroot;
    private string $reprintDirectory;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/push-coordinator-' . bin2hex(random_bytes(8));
        $this->docroot = $this->root . '/docroot';
        $this->reprintDirectory = $this->root . '/reprint';
        mkdir($this->docroot, 0700, true);
        mkdir($this->reprintDirectory, 0700, true);
    }

    protected function tearDown(): void {
        $this->removeTree($this->root);
    }

    public function testOwnerEpochFencesDisplacedPushAndCommitAdvancesGenerationOnce(): void {
        $coordinator = new Site_Export_Push_Coordinator(
            $this->reprintDirectory,
            $this->docroot,
            ['preserved']
        );
        $firstPushSessionId = str_repeat('1', 32);
        $secondPushSessionId = str_repeat('2', 32);

        $firstOwner = $coordinator->claim_owner($firstPushSessionId, false, null, null);
        $this->assertSame(1, $firstOwner['ownership_epoch']);
        $this->assertSame(0, $firstOwner['document_root_generation']);
        $coordinationDirectory = $this->reprintDirectory . '/.reprint/push';
        $this->assertFileExists($coordinationDirectory . '/state.json');
        $this->assertFileExists($coordinationDirectory . '/state.lock');
        $this->assertFileExists($coordinationDirectory . '/push-request.lock');
        $this->assertFileExists($coordinationDirectory . '/file-read.lock');

        try {
            $coordinator->claim_owner($secondPushSessionId, false, null, null);
            $this->fail('A second push owner was admitted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame(Site_Export_Push_Session::ERROR_SYNC_LOCKED, $exception->get_error_code());
            $this->assertSame($firstPushSessionId, $exception->get_context()['blocking_push_session_id']);
            $this->assertSame(1, $exception->get_context()['blocking_ownership_epoch']);
        }

        $secondOwner = $coordinator->claim_owner($secondPushSessionId, true, $firstPushSessionId, 1);
        $this->assertSame(2, $secondOwner['ownership_epoch']);

        try {
            $coordinator->with_owner_request($firstPushSessionId, 1, static function (): void {});
            $this->fail('A displaced owner completed a request.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame(Site_Export_Push_Session::ERROR_SYNC_OVERTAKEN, $exception->get_error_code());
        }

        $commit = $coordinator->with_owner_request(
            $secondPushSessionId,
            2,
            function () use ($coordinator, $secondPushSessionId): array {
                return $coordinator->with_commit(
                    $secondPushSessionId,
                    2,
                    function () use ($coordinator): array {
                        try {
                            $coordinator->with_file_read(static function (): void {});
                            $this->fail('A pull began while the document root was committing.');
                        } catch (Site_Export_Push_Exception $exception) {
                            $this->assertSame(Site_Export_Push_Session::ERROR_SYNC_LOCKED, $exception->get_error_code());
                        }
                        return ['phase' => 'complete'];
                    }
                );
            }
        );
        $this->assertSame('complete', $commit['phase']);
        $this->assertSame(1, $coordinator->get_document_root_generation());
        $this->assertSame(1, $coordinator->with_file_read(static function (int $generation): int {
            return $generation;
        }));

        $coordinator->with_owner_request(
            $secondPushSessionId,
            2,
            function () use ($coordinator, $secondPushSessionId): void {
                $coordinator->with_commit(
                    $secondPushSessionId,
                    2,
                    static function (): array {
                        return ['phase' => 'complete'];
                    }
                );
            }
        );
        $this->assertSame(1, $coordinator->get_document_root_generation());
    }

    private function removeTree(string $path): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
