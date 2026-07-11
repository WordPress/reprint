<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-journal.php';

/** Covers stable per-target baseline naming; the driver owns all baseline I/O. */
final class PushJournalTest extends TestCase {

    public function testSiteKeyIdentifiesTheSiteNotTheUrlSpelling(): void {
        $canonical = PushJournal::site_key('https://example.com/blog');

        self::assertSame($canonical, PushJournal::site_key('http://example.com/blog'));
        self::assertSame($canonical, PushJournal::site_key('https://EXAMPLE.com/blog/'));
        self::assertSame($canonical, PushJournal::site_key('https://example.com/blog?preview=1'));
        self::assertSame($canonical, PushJournal::site_key('example.com/blog'));
        self::assertNotSame($canonical, PushJournal::site_key('https://example.com'));
        self::assertNotSame($canonical, PushJournal::site_key('https://example.com:8080/blog'));
        self::assertNotSame($canonical, PushJournal::site_key('https://example.org/blog'));
        self::assertStringStartsWith('example.com-blog-', $canonical);
    }

    public function testSiteKeyRejectsUrlsWithoutAHost(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no host');
        PushJournal::site_key('/just/a/path');
    }

    public function testJournalOnlyNamesTheBaselineAndPerformsNoUnboundedIo(): void {
        $state_dir = sys_get_temp_dir() . '/push-journal-' . bin2hex(random_bytes(8));
        $journal = new PushJournal($state_dir, 'https://example.com/blog');

        self::assertSame(
            $state_dir . '/push/' . PushJournal::site_key('https://example.com/blog') . '/last-sync-local-files.jsonl',
            $journal->local_files_baseline_path
        );
        self::assertDirectoryDoesNotExist($state_dir);
        self::assertFalse(method_exists($journal, 'diff_local_files'));
        self::assertFalse(method_exists($journal, 'capture_local_files_baseline'));
    }
}
