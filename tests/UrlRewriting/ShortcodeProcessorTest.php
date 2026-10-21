<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\Shortcode\ShortcodeProcessor;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class ShortcodeProcessorTest extends TestCase {
    /** Long incomplete candidates must not repeatedly walk the remaining text. */
    public function testRepeatedQuotedCandidatesFinishWithinTheScanWindow(): void
    {
        $script = __DIR__ . '/fixtures/shortcode-scan.php';
        $directory = sys_get_temp_dir() . '/shortcode-scan-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $process = proc_open([PHP_BINARY, $script], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $directory . '/stdout', 'w'],
            2 => ['file', $directory . '/stderr', 'w'],
        ], $pipes);
        $this->assertIsResource($process);
        try {
            // The completion guard leaves room for slow shared CI runners.
            $deadline = microtime(true) + 30;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertFalse($status['running'], 'Scanning a 1 MiB shortcode value exceeded thirty seconds.');
            $this->assertSame(0, $status['exitcode'], file_get_contents($directory . '/stderr'));
            $this->assertSame("complete\n", file_get_contents($directory . '/stdout'));
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            unlink($directory . '/stdout');
            unlink($directory . '/stderr');
            rmdir($directory);
        }
    }

    /** Candidates inside an incomplete quote still start with their own quote state. */
    public function testRecoversCompleteTokensInsideAnIncompleteQuotedCandidate(): void
    {
        $processor = new ShortcodeProcessor(
            'Before [incomplete "text [image src=\'https://old.example/photo\'] [gallery]'
        );
        $this->assertTrue($processor->next_token());
        $this->assertSame('Before [incomplete "text ', $processor->get_token_text());
        $this->assertTrue($processor->next_shortcode('image'));
        $this->assertSame('https://old.example/photo', $processor->get_attribute('src'));
        $this->assertTrue($processor->next_shortcode('gallery'));
        $this->assertFalse($processor->next_token());
    }

    public function testReportsNestedOpenersAndClosersIndependently(): void
    {
        $processor = new ShortcodeProcessor(
            '[row level="1"][row level="2"]Inner[/row][/row]'
        );
        $tokens = [];

        while ($processor->next_shortcode('row')) {
            $tokens[] = [
                $processor->is_tag_closer() ? '/row' : 'row',
                $processor->get_attribute('level'),
            ];
        }

        $this->assertSame(
            [
                ['row', '1'],
                ['row', '2'],
                ['/row', null],
                ['/row', null],
            ],
            $tokens
        );
    }

    public function testKeepsHtmlAndBracketsInsideAQuotedAttribute(): void
    {
        $processor = new ShortcodeProcessor(
            '[builder text="<a href=\'https://old.example/file\'>Keep ] here</a>" size="large"]'
        );

        $this->assertTrue($processor->next_shortcode('builder'));
        $this->assertSame(
            '<a href=\'https://old.example/file\'>Keep ] here</a>',
            $processor->get_attribute('text')
        );
        $this->assertSame('large', $processor->get_attribute('size'));
    }

    public function testAttributeUpdatePreservesUnrelatedSourceBytes(): void
    {
        $input = "Before [builder image='https://old.example/file.jpg' label=wide] After";
        $processor = new ShortcodeProcessor($input);

        $this->assertTrue($processor->next_shortcode('builder'));
        while ($processor->next_attribute()) {
            if ($processor->get_attribute_name() === 'image') {
                $this->assertTrue(
                    $processor->set_attribute_value('https://new.example/file.jpg')
                );
            }
        }

        $this->assertSame(
            "Before [builder image='https://new.example/file.jpg' label=wide] After",
            $processor->get_updated_text()
        );
    }

    /**
     * The shortcode may sit inside an outer grammar that uses the other quote
     * byte as its own delimiter, so an update never switches or adds quotes.
     */
    #[DataProvider('delimiterChangingUpdateProvider')]
    public function testAttributeUpdateRefusesToChangeDelimiters(string $input, string $value): void
    {
        $processor = new ShortcodeProcessor($input);

        $this->assertTrue($processor->next_shortcode('builder'));
        $this->assertTrue($processor->next_attribute());
        $this->assertFalse($processor->set_attribute_value($value));
        $this->assertSame($input, $processor->get_updated_text());
    }

    /** @return array<string, array{string, string}> */
    public static function delimiterChangingUpdateProvider(): array
    {
        return [
            'single-quoted value gains an apostrophe' => ["[builder link='https://old.example/']", "https://new.example/'"],
            'double-quoted value gains a double quote' => ['[builder link="https://old.example/"]', 'https://new.example/"'],
            'unquoted value gains an apostrophe' => ['[builder link=https://old.example/x]', "https://new.example/'"],
            'unquoted value gains whitespace' => ['[builder link=https://old.example/x]', 'https://new.example/ x'],
            'unquoted last value gains a trailing slash' => ['[builder link=https://old.example/x]', 'https://new.example/x/'],
        ];
    }

    public function testAttributeUpdateKeepsEscapedDelimiterBytes(): void
    {
        $input = '[builder data="{\"url\":\"https://old.example/file\",\"label\":\"it\'s here\"}"]';
        $processor = new ShortcodeProcessor($input);

        $this->assertTrue($processor->next_shortcode('builder'));
        $this->assertTrue($processor->next_attribute());
        $this->assertTrue(
            $processor->set_attribute_value(
                '{\"url\":\"https://new.example/file\",\"label\":\"it\'s here\"}'
            )
        );

        $this->assertSame(
            '[builder data="{\"url\":\"https://new.example/file\",\"label\":\"it\'s here\"}"]',
            $processor->get_updated_text()
        );
    }
}
