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
        $this->assertDirectoryDoesNotExist($this->staging_dir . '/files/wp-content');
        $this->assertDirectoryDoesNotExist($this->staging_dir . '/verified/wp-content');
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
        $this->assertSame(['index.php'], $result['skipped_paths'], 'callers audit-log the protected paths');
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
    // Deletions
    // ---------------------------------------------------------------

    public function testDeleteEntriesRemoveFilesDirectoriesAndLinks(): void
    {
        file_put_contents($this->target_root . '/stale.php', 'old');
        mkdir($this->target_root . '/stale-dir/nested', 0700, true);
        file_put_contents($this->target_root . '/stale-dir/nested/deep.txt', 'old');
        $shared = $this->target_root . '-shared-del';
        mkdir($shared, 0700, true);
        file_put_contents($shared . '/keep.txt', 'shared');
        symlink($shared, $this->target_root . '/stale-link');
        $this->stageVerified('fresh.txt', 'new');
        // An earlier resume staged bytes for a file the remote has since
        // deleted; the delete pass must consume them with the deletion.
        $this->stageVerified('stale.php', 'obsolete bytes');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'fresh.txt', 'size' => 3],
            ['artifact_id' => 'stale.php', 'delete' => true],
            ['artifact_id' => 'stale-dir', 'delete' => true],
            ['artifact_id' => 'stale-link', 'delete' => true],
        ]);

        try {
            $result = $this->makeApply()->apply($manifest);

            $this->assertSame('applied', $result['status']);
            $this->assertSame(1, $result['applied']);
            $this->assertSame(3, $result['deleted']);
            $this->assertFileDoesNotExist($this->target_root . '/stale.php');
            $this->assertDirectoryDoesNotExist($this->target_root . '/stale-dir');
            $this->assertFalse(is_link($this->target_root . '/stale-link'));
            $this->assertSame(
                'shared',
                file_get_contents($shared . '/keep.txt'),
                'deleting the link must not follow it'
            );
            $this->assertSame('new', file_get_contents($this->target_root . '/fresh.txt'));
            $this->assertFileDoesNotExist(
                $this->staging_dir . '/files/stale.php',
                'staged garbage under a deleted id is consumed'
            );
            $this->assertFileDoesNotExist($this->staging_dir . '/verified/stale.php');
        } finally {
            $this->removeDir($shared);
        }
    }

    public function testDeletionsRunBeforeMovesRegardlessOfManifestOrder(): void
    {
        // The path was a file; the new tree turns it into a directory: the
        // transfer deletes 'swap' and creates 'swap/new.txt'. Move-first
        // would build the directory, land the file, and then have the
        // delete pass tear down the freshly-created tree. The manifest
        // lists the create first to prove passes, not line order, decide.
        file_put_contents($this->target_root . '/swap', 'was a file');
        $this->stageVerified('swap/new.txt', 'dir member');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'swap/new.txt', 'size' => 10],
            ['artifact_id' => 'swap', 'delete' => true],
        ]);

        $result = $this->makeApply()->apply($manifest);

        $this->assertSame('applied', $result['status']);
        $this->assertSame([1, 1], [$result['applied'], $result['deleted']]);
        $this->assertSame('dir member', file_get_contents($this->target_root . '/swap/new.txt'));
    }

    public function testDeletionsAreIdempotentAcrossReruns(): void
    {
        file_put_contents($this->target_root . '/goner.txt', 'x');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'goner.txt', 'delete' => true],
        ]);

        $first = $this->makeApply()->apply($manifest);
        $this->assertSame(['applied', 1], [$first['status'], $first['deleted']]);
        $this->assertFileDoesNotExist($this->target_root . '/goner.txt');

        // A kill after apply but before the sender's cache update re-sends
        // the same deletion; the path being gone already is the done state.
        $again = $this->stageManifest([
            ['artifact_id' => 'goner.txt', 'delete' => true],
        ], '.manifest-2.jsonl');
        $second = $this->makeApply()->apply($again);

        $this->assertSame('applied', $second['status']);
        $this->assertSame(0, $second['deleted']);
        $this->assertSame(1, $second['already_applied']);
    }

    public function testSkipPolicyDoesNotProtectOwnedDeletions(): void
    {
        // Delete entries only ever come from the sender's own ship record
        // (pull's index, push's cache), so preserve-local — which protects
        // what the sync never owned — does not stand in their way. This is
        // trunk's diff-phase behavior: owned files delete under
        // preserve-local too.
        file_put_contents($this->target_root . '/shipped-earlier.txt', 'ours');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'shipped-earlier.txt', 'delete' => true],
        ]);

        $result = $this->makeApply(['on_existing' => 'skip'])->apply($manifest);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(0, $result['skipped']);
        $this->assertFileDoesNotExist($this->target_root . '/shipped-earlier.txt');
    }

    public function testSkipPolicyDoesNotProtectOwnedEntries(): void
    {
        // An owned entry's occupant is the transfer's own previous copy:
        // preserve-local must not freeze a file at the version the first
        // pull shipped. The unowned sibling still gets protected.
        file_put_contents($this->target_root . '/ours.php', 'v1 from a previous window');
        file_put_contents($this->target_root . '/theirs.php', 'the user made this');
        $this->stageVerified('ours.php', 'v2');
        $this->stageVerified('theirs.php', 'remote bytes');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'ours.php', 'size' => 2, 'owned' => true],
            ['artifact_id' => 'theirs.php', 'size' => 12],
        ]);

        $result = $this->makeApply(['on_existing' => 'skip'])->apply($manifest);

        $this->assertSame('applied', $result['status']);
        $this->assertSame([1, 1], [$result['applied'], $result['skipped']]);
        $this->assertSame(['theirs.php'], $result['skipped_paths']);
        $this->assertSame('v2', file_get_contents($this->target_root . '/ours.php'));
        $this->assertSame('the user made this', file_get_contents($this->target_root . '/theirs.php'));
    }

    public function testSymlinkedParentGuardProtectsDeletions(): void
    {
        mkdir($this->target_root . '/wp-content', 0700, true);
        $shared = $this->target_root . '-shared-linked';
        mkdir($shared, 0700, true);
        file_put_contents($shared . '/keep.php', 'shared');
        symlink($shared, $this->target_root . '/wp-content/plugins');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'wp-content/plugins/keep.php', 'delete' => true],
        ]);

        try {
            $result = $this->makeApply(['refuse_symlinked_parents' => true])->apply($manifest);

            $this->assertSame(['applied', 1], [$result['status'], $result['skipped']]);
            $this->assertSame(0, $result['deleted']);
            $this->assertSame('shared', file_get_contents($shared . '/keep.php'));
        } finally {
            $this->removeDir($shared);
        }
    }

    public function testDeletePassIoErrorIsTypedAndRerunResumes(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('permission barriers do not bind as root');
        }
        mkdir($this->target_root . '/locked', 0700, true);
        file_put_contents($this->target_root . '/locked/goner.txt', 'x');
        $manifest = $this->stageManifest([
            ['artifact_id' => 'locked/goner.txt', 'delete' => true],
        ]);

        // The environment turns hostile mid-transfer: the parent loses its
        // write bit, so the unlink fails.
        chmod($this->target_root . '/locked', 0555);
        try {
            $result = $this->makeApply()->apply($manifest);

            $this->assertSame(['rejected', 'io_error'], [$result['status'], $result['reason']]);
            $this->assertStringContainsString('delete: locked/goner.txt', (string) $result['detail']);
        } finally {
            chmod($this->target_root . '/locked', 0700);
        }

        // A rejection consumes nothing; once the operator fixes the
        // permissions, rerunning the same manifest finishes the window.
        $second = $this->makeApply()->apply($manifest);

        $this->assertSame('applied', $second['status']);
        $this->assertSame(1, $second['deleted']);
        $this->assertFileDoesNotExist($this->target_root . '/locked/goner.txt');
    }

    public function testMalformedDeleteEntriesRejectTheManifest(): void
    {
        // delete:false is not a delete, so the entry needs its size.
        $manifest = $this->stageManifest([
            ['artifact_id' => 'x.txt', 'delete' => false],
        ]);
        $result = $this->makeApply()->apply($manifest);
        $this->assertSame(['rejected', 'manifest_invalid'], [$result['status'], $result['reason']]);

        // Delete ids go through the same path rule as everything else.
        $this->stageVerified('hostile-delete.jsonl', json_encode(['artifact_id' => '../escape', 'delete' => true]) . "\n");
        $hostile = $this->makeApply()->apply('hostile-delete.jsonl');
        $this->assertStringContainsString('invalid artifact id', (string) $hostile['detail']);

        // One verdict per path: a same-id create-plus-delete is ambiguous
        // on rerun, and neither sender can produce it.
        $this->stageVerified(
            'twice.jsonl',
            json_encode(['artifact_id' => 'x.txt', 'size' => 1]) . "\n"
                . json_encode(['artifact_id' => 'x.txt', 'delete' => true]) . "\n"
        );
        $twice = $this->makeApply()->apply('twice.jsonl');
        $this->assertStringContainsString('duplicates an artifact id', (string) $twice['detail']);
    }

    public function testOnProtectedReportsEveryEntryBeyondTheResponseCap(): void
    {
        // skipped_paths is a bounded-HTTP-response diagnostic, capped at
        // 1000. In-process callers reconcile per-entry state through
        // on_protected, which must see every protected id — an index
        // catch-up driven by the capped list would silently skip entries
        // past the cap.
        $manifest_entries = [];
        for ($i = 0; $i < 1001; $i++) {
            $name = sprintf('kept-%04d.txt', $i);
            file_put_contents($this->target_root . '/' . $name, 'local');
            $manifest_entries[] = ['artifact_id' => $name, 'size' => 5];
        }
        $manifest = $this->stageManifest($manifest_entries);

        $reported = [];
        $apply = $this->makeApply([
            'on_existing' => 'skip',
            'on_protected' => static function (string $artifact_id) use (&$reported): void {
                $reported[] = $artifact_id;
            },
        ]);
        $result = $apply->apply($manifest);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(1001, $result['skipped']);
        $this->assertCount(1000, $result['skipped_paths'], 'the response list stays capped');
        $this->assertCount(1001, $reported, 'the callback sees every protected entry');
        $this->assertSame('kept-1000.txt', end($reported));
    }

    // ---------------------------------------------------------------
    // Preflight facts on "ready"
    // ---------------------------------------------------------------

    public function testReadyReportsFreeSpaceForTheSendersPreflight(): void
    {
        $result = $this->makeApply()->apply('not-yet-staged', true);

        $this->assertSame('ready', $result['status']);
        $this->assertIsInt($result['staging_free_bytes']);
        $this->assertIsInt($result['target_free_bytes']);
        $this->assertGreaterThan(0, $result['target_free_bytes']);
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
