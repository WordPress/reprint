<?php

use PHPUnit\Framework\TestCase;

final class StagedApplyTest extends TestCase {

    private string $staging_dir;

    private string $target_root;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->staging_dir = sys_get_temp_dir() . '/staged-apply-staging-' . $suffix;
        $this->target_root = sys_get_temp_dir() . '/staged-apply-target-' . $suffix;
        mkdir($this->target_root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->staging_dir);
        $this->removeDir($this->target_root);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    private function makeApply(array $overrides = []): Site_Export_Staged_Apply
    {
        return new Site_Export_Staged_Apply(array_merge([
            'staging_dir' => $this->staging_dir,
            'target_root' => $this->target_root,
        ], $overrides));
    }

    private function store(): Site_Export_Staged_Artifacts
    {
        return new Site_Export_Staged_Artifacts($this->staging_dir);
    }

    private function stageVerified(string $artifact_id, string $body): void
    {
        $store = $this->store();
        if ($body !== '') {
            $result = $store->append($artifact_id, 0, $body);
            assert($result['status'] === 'accepted');
        }
        $finalized = $store->finalize($artifact_id, strlen($body));
        assert($finalized['status'] === 'verified');
    }

    /**
     * Stages a manifest listing the given artifacts, returning its id.
     */
    private function stageManifest(array $entries, string $manifest_id = '.manifest.jsonl'): string
    {
        $lines = '';
        foreach ($entries as $entry) {
            $lines .= json_encode($entry) . "\n";
        }
        $this->stageVerified($manifest_id, $lines);
        return $manifest_id;
    }

    // ---------------------------------------------------------------
    // The happy window
    // ---------------------------------------------------------------

    public function testAppliesAVerifiedTransferIntoTheTargetRoot(): void
    {
        $this->stageVerified('index.php', '<?php new');
        $this->stageVerified('wp-content/themes/t/style.css', 'body{}');
        $this->stageVerified('empty.txt', '');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'index.php', 'size' => 9],
            ['artifact_id' => 'wp-content/themes/t/style.css', 'size' => 6],
            ['artifact_id' => 'empty.txt', 'size' => 0],
        ]);

        $result = $this->makeApply()->apply($manifest);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(3, $result['applied']);
        $this->assertSame(0, $result['already_applied']);
        $this->assertSame('<?php new', file_get_contents($this->target_root . '/index.php'));
        $this->assertSame('body{}', file_get_contents($this->target_root . '/wp-content/themes/t/style.css'));
        $this->assertSame('', file_get_contents($this->target_root . '/empty.txt'));

        // The transfer is consumed: files, markers, and the manifest.
        $this->assertFileDoesNotExist($this->staging_dir . '/files/index.php');
        $this->assertFileDoesNotExist($this->staging_dir . '/verified/index.php');
        $this->assertFileDoesNotExist($this->staging_dir . '/files/.manifest.jsonl');
        $this->assertFalse($this->store()->status('index.php')['verified']);
    }

    public function testReplacesExistingTargetFilesAtomically(): void
    {
        mkdir($this->target_root . '/wp-content', 0700, true);
        file_put_contents($this->target_root . '/wp-content/old.php', 'old content');
        $this->stageVerified('wp-content/old.php', 'new!');
        $manifest = $this->stageManifest([['artifact_id' => 'wp-content/old.php', 'size' => 4]]);

        $result = $this->makeApply()->apply($manifest);

        $this->assertSame('applied', $result['status']);
        $this->assertSame('new!', file_get_contents($this->target_root . '/wp-content/old.php'));
    }

    public function testApplyClearsACursorNamingAConsumedArtifact(): void
    {
        $this->stageVerified('artifact.bin', 'payload');
        // Simulate finalize killed before its best-effort cursor clear.
        file_put_contents(
            $this->staging_dir . '/state.json',
            json_encode(['artifact_id' => 'artifact.bin', 'committed_bytes' => 7])
        );
        $manifest = $this->stageManifest([['artifact_id' => 'artifact.bin', 'size' => 7]]);

        $this->assertSame('applied', $this->makeApply()->apply($manifest)['status']);

        $state = json_decode( (string) file_get_contents($this->staging_dir . '/state.json'), true);
        $this->assertNull($state['artifact_id'], 'a stale cursor must not answer a future upload');
    }

    // ---------------------------------------------------------------
    // Same-device requirement
    // ---------------------------------------------------------------

    public function testCrossDeviceStagingIsRejectedBeforeAnythingMoves(): void
    {
        $this->stageVerified('index.php', 'payload');
        $manifest = $this->stageManifest([['artifact_id' => 'index.php', 'size' => 7]]);
        $apply = $this->makeApply([
            // Staging and target report different stat devices.
            'device_id' => function (string $path): ?int {
                return strpos($path, 'staged-apply-staging') !== false ? 11 : 22;
            },
        ]);

        $result = $apply->apply($manifest);

        $this->assertSame(['rejected', 'cross_device'], [$result['status'], $result['reason']]);
        $this->assertStringContainsString('device', (string) $result['detail']);
        $this->assertFileDoesNotExist($this->target_root . '/index.php');
        $this->assertFileExists($this->staging_dir . '/files/index.php', 'nothing may move');

        // The probe form rejects identically, before any upload happens.
        $probe = $apply->apply('never-staged-manifest', true);
        $this->assertSame(['rejected', 'cross_device'], [$probe['status'], $probe['reason']]);
    }

    public function testCheckOnlyReportsReadyBeforeTheTransferExists(): void
    {
        $result = $this->makeApply()->apply('not-yet-staged-manifest', true);

        $this->assertSame('ready', $result['status']);
        $this->assertDirectoryDoesNotExist($this->staging_dir . '/files');
    }

    public function testCheckOnlyValidatesAFullTransferWithoutMoving(): void
    {
        $this->stageVerified('a.txt', 'aaa');
        $manifest = $this->stageManifest([['artifact_id' => 'a.txt', 'size' => 3]]);

        $result = $this->makeApply()->apply($manifest, true);

        $this->assertSame('ready', $result['status']);
        $this->assertFileDoesNotExist($this->target_root . '/a.txt');
        $this->assertFileExists($this->staging_dir . '/files/a.txt');
    }

    // ---------------------------------------------------------------
    // Whole-transfer validation before the first rename
    // ---------------------------------------------------------------

    public function testUnverifiedArtifactRejectsTheWholeTransfer(): void
    {
        $this->stageVerified('good-1.txt', 'aaa');
        $this->store()->append('half-done.txt', 0, 'bb');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'good-1.txt', 'size' => 3],
            ['artifact_id' => 'half-done.txt', 'size' => 2],
        ]);

        $result = $this->makeApply()->apply($manifest);

        $this->assertSame(['rejected', 'unverified_artifact'], [$result['status'], $result['reason']]);
        $this->assertSame('half-done.txt', $result['detail']);
        $this->assertFileDoesNotExist(
            $this->target_root . '/good-1.txt',
            'no partial apply of a half-verified transfer'
        );
    }

    public function testMissingArtifactRejectsTheWholeTransfer(): void
    {
        $this->stageVerified('present.txt', 'here');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'present.txt', 'size' => 4],
            ['artifact_id' => 'never-uploaded.txt', 'size' => 9],
        ]);

        $result = $this->makeApply()->apply($manifest);

        $this->assertSame(['rejected', 'missing_artifact'], [$result['status'], $result['reason']]);
        $this->assertFileDoesNotExist($this->target_root . '/present.txt');
    }

    public function testManifestProblemsAreTypedRejections(): void
    {
        $apply = $this->makeApply();

        $this->stageVerified('torn.jsonl', '{"artifact_id":"x","si');
        $torn = $apply->apply('torn.jsonl');
        $this->assertSame(['rejected', 'manifest_invalid'], [$torn['status'], $torn['reason']]);

        $this->stageVerified('selfish.jsonl', json_encode(['artifact_id' => 'selfish.jsonl', 'size' => 1]) . "\n");
        $selfish = $apply->apply('selfish.jsonl');
        $this->assertSame('manifest lists itself', $selfish['detail']);

        $this->stageVerified('hostile.jsonl', json_encode(['artifact_id' => '../escape', 'size' => 1]) . "\n");
        $hostile = $apply->apply('hostile.jsonl');
        $this->assertStringContainsString('invalid artifact id', (string) $hostile['detail']);

        $unverified = $apply->apply('never-staged.jsonl');
        $this->assertSame(['rejected', 'manifest_invalid'], [$unverified['status'], $unverified['reason']]);
    }

    // ---------------------------------------------------------------
    // Preserve-local policies
    // ---------------------------------------------------------------

    public function testSkipPolicyLetsAnOccupiedTargetPathWin(): void
    {
        file_put_contents($this->target_root . '/index.php', 'local wins');
        $this->stageVerified('index.php', 'remote bytes');
        $this->stageVerified('fresh.txt', 'lands');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'index.php', 'size' => 12],
            ['artifact_id' => 'fresh.txt', 'size' => 5],
        ]);
        $apply = $this->makeApply(['on_existing' => 'skip']);

        $result = $apply->apply($manifest);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(1, $result['applied']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('local wins', file_get_contents($this->target_root . '/index.php'));
        $this->assertSame('lands', file_get_contents($this->target_root . '/fresh.txt'));
        $this->assertFileDoesNotExist(
            $this->staging_dir . '/files/index.php',
            'protected entries consume their staged bytes'
        );
        $this->assertFileDoesNotExist($this->staging_dir . '/verified/index.php');
    }

    public function testSkipPolicyProtectsDanglingSymlinksAtTheTarget(): void
    {
        symlink($this->target_root . '/never-created', $this->target_root . '/link.php');
        $this->stageVerified('link.php', 'remote');
        $manifest = $this->stageManifest([['artifact_id' => 'link.php', 'size' => 6]]);

        $result = $this->makeApply(['on_existing' => 'skip'])->apply($manifest);

        $this->assertSame(['applied', 1], [$result['status'], $result['skipped']]);
        $this->assertTrue(is_link($this->target_root . '/link.php'), 'the symlink survives');
    }

    public function testSymlinkedParentGuardNeverWritesThroughTheLink(): void
    {
        // A hosting layout: wp-content/plugins is a symlink to a shared dir.
        mkdir($this->target_root . '/wp-content', 0700, true);
        $shared = $this->target_root . '-shared-plugins';
        mkdir($shared, 0700, true);
        symlink($shared, $this->target_root . '/wp-content/plugins');

        $this->stageVerified('wp-content/plugins/p/p.php', 'through the link');
        $this->stageVerified('wp-content/regular.txt', 'fine');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'wp-content/plugins/p/p.php', 'size' => 16],
            ['artifact_id' => 'wp-content/regular.txt', 'size' => 4],
        ]);

        try {
            $result = $this->makeApply(['refuse_symlinked_parents' => true])->apply($manifest);

            $this->assertSame('applied', $result['status']);
            $this->assertSame(1, $result['applied']);
            $this->assertSame(1, $result['skipped']);
            $this->assertSame([], glob($shared . '/*'), 'nothing may appear behind the link');
            $this->assertSame('fine', file_get_contents($this->target_root . '/wp-content/regular.txt'));
        } finally {
            $this->removeDir($shared);
        }
    }

    public function testSkipPolicyRerunWithNothingStagedStillApplies(): void
    {
        file_put_contents($this->target_root . '/protected.php', 'kept');
        $this->stageVerified('protected.php', 'remote-1');
        $this->stageVerified('normal.txt', 'moved');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'protected.php', 'size' => 8],
            ['artifact_id' => 'normal.txt', 'size' => 5],
        ]);
        $apply = $this->makeApply(['on_existing' => 'skip']);
        $this->assertSame('applied', $apply->apply($manifest)['status']);

        // The next transfer lists the protected path again. Nothing is
        // staged for it and its local size differs from the manifest —
        // without the policy-first classification this would reject the
        // whole run as missing_artifact.
        $this->stageVerified('normal.txt', 'move2');
        $again = $this->stageManifest([
            ['artifact_id' => 'protected.php', 'size' => 8],
            ['artifact_id' => 'normal.txt', 'size' => 5],
        ], '.manifest-2.jsonl');

        $result = $apply->apply($again);

        // Preserve-local means never overwrite — including files an earlier
        // run applied. The rerun protects both and consumes the fresh bytes.
        $this->assertSame('applied', $result['status']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame('kept', file_get_contents($this->target_root . '/protected.php'));
        $this->assertSame('moved', file_get_contents($this->target_root . '/normal.txt'));
        $this->assertFileDoesNotExist($this->staging_dir . '/files/normal.txt');
    }

    public function testCheckOnlyReportsProtectedEntriesWithoutConsuming(): void
    {
        file_put_contents($this->target_root . '/here.php', 'local');
        $this->stageVerified('here.php', 'remote');
        $manifest = $this->stageManifest([['artifact_id' => 'here.php', 'size' => 6]]);

        $result = $this->makeApply(['on_existing' => 'skip'])->apply($manifest, true);

        $this->assertSame(['ready', 1], [$result['status'], $result['skipped']]);
        $this->assertFileExists($this->staging_dir . '/files/here.php', 'check_only must not consume');
    }

    public function testInvalidOnExistingPolicyIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeApply(['on_existing' => 'merge']);
    }

    // ---------------------------------------------------------------
    // Type swaps between transfers
    // ---------------------------------------------------------------

    public function testReplacesADirectoryWithAFile(): void
    {
        // The previous site version had a directory here; the new one has
        // a file. Trunk's writer replaces it, so apply must too.
        mkdir($this->target_root . '/was-a-dir/nested', 0700, true);
        file_put_contents($this->target_root . '/was-a-dir/nested/old.txt', 'old');
        $this->stageVerified('was-a-dir', 'now a file');
        $manifest = $this->stageManifest([['artifact_id' => 'was-a-dir', 'size' => 10]]);

        $result = $this->makeApply()->apply($manifest);

        $this->assertSame('applied', $result['status']);
        $this->assertSame('now a file', file_get_contents($this->target_root . '/was-a-dir'));
    }

    public function testReplacesASymlinkWithoutFollowingIt(): void
    {
        // A symlinked directory occupies the path; replacing it must swap
        // the link itself and never reach through into the shared target.
        $shared = $this->target_root . '-shared';
        mkdir($shared, 0700, true);
        file_put_contents($shared . '/keep.txt', 'shared content');
        symlink($shared, $this->target_root . '/linked');
        $this->stageVerified('linked', 'plain file now');
        $manifest = $this->stageManifest([['artifact_id' => 'linked', 'size' => 14]]);

        try {
            $result = $this->makeApply()->apply($manifest);

            $this->assertSame('applied', $result['status']);
            $this->assertFalse(is_link($this->target_root . '/linked'));
            $this->assertSame('plain file now', file_get_contents($this->target_root . '/linked'));
            $this->assertSame(
                'shared content',
                file_get_contents($shared . '/keep.txt'),
                'the link target must survive untouched'
            );
        } finally {
            $this->removeDir($shared);
        }
    }

    public function testClearsAFileBlockingTheParentChain(): void
    {
        // wp-content used to be a file; the new tree needs it as a dir.
        file_put_contents($this->target_root . '/wp-content', 'blocking file');
        $this->stageVerified('wp-content/plugins/p.php', '<?php');
        $manifest = $this->stageManifest([['artifact_id' => 'wp-content/plugins/p.php', 'size' => 5]]);

        $result = $this->makeApply()->apply($manifest);

        $this->assertSame('applied', $result['status']);
        $this->assertSame('<?php', file_get_contents($this->target_root . '/wp-content/plugins/p.php'));
    }

    // ---------------------------------------------------------------
    // Kill windows and contention
    // ---------------------------------------------------------------

    public function testRerunAfterAKillMidApplyFinishesTheWindow(): void
    {
        $this->stageVerified('moved-before-kill.txt', 'first');
        $this->stageVerified('still-staged.txt', 'second');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'moved-before-kill.txt', 'size' => 5],
            ['artifact_id' => 'still-staged.txt', 'size' => 6],
        ]);

        // The kill hit between the first rename and its marker unlink.
        rename(
            $this->staging_dir . '/files/moved-before-kill.txt',
            $this->target_root . '/moved-before-kill.txt'
        );

        $result = $this->makeApply()->apply($manifest);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(1, $result['applied']);
        $this->assertSame(1, $result['already_applied']);
        $this->assertSame('first', file_get_contents($this->target_root . '/moved-before-kill.txt'));
        $this->assertSame('second', file_get_contents($this->target_root . '/still-staged.txt'));
        $this->assertFileDoesNotExist(
            $this->staging_dir . '/verified/moved-before-kill.txt',
            'the leftover marker is consumed by the rerun'
        );
    }

    public function testBusyWhileAWriterHoldsTheStore(): void
    {
        $this->stageVerified('a.txt', 'aaa');
        $manifest = $this->stageManifest([['artifact_id' => 'a.txt', 'size' => 3]]);

        $holder = fopen($this->staging_dir . '/lock', 'r+b');
        flock($holder, LOCK_EX);
        $result = $this->makeApply()->apply($manifest);
        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertSame('busy', $result['status']);
        $this->assertFileDoesNotExist($this->target_root . '/a.txt');
    }

    public function testMissingTargetRootIsRejected(): void
    {
        $apply = $this->makeApply(['target_root' => $this->target_root . '/never-created']);

        $result = $apply->apply('whatever', true);

        $this->assertSame(['rejected', 'target_missing'], [$result['status'], $result['reason']]);
    }
}
