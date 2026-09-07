<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\CSSURLProcessor;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class CssFileUrlRewritingTest extends TestCase {

    /** Splitting at every byte checks incomplete schemes, hosts, paths, escapes, and delimiters. */
    public function testEveryDownloadBoundaryPreservesCssAndRewritesEachUrlOnce(): void
    {
        $input = '@import "https://old.example/assets/theme.css";'
            . '.hero{background:url(https://old.example/photo.jpg)}'
            . '@font-face{src:url(//old.example/font.woff2)}'
            . '.escaped{background:url(https:\\/\\/old.example\\/photo.jpg)}'
            . '.other{background:url(https://old.example.org/photo.jpg)}';
        $expected = str_replace(
            ['https://old.example/assets/', 'https://old.example/photo', '//old.example/font', 'https:\\/\\/old.example\\/photo'],
            ['http://old.example/local/styles/', 'http://old.example/local/photo', '//old.example/local/font', 'http://old.example/local\\/photo'],
            $input
        );
        $mapping = [
            'https://old.example' => 'http://old.example/local',
            'https://old.example/assets' => 'http://old.example/local/styles',
        ];
        $input_bytes = strlen($input);
        for ($split = 0; $split <= $input_bytes; ++$split) {
            $stream = CSSURLProcessor::create_for_streaming($mapping);
            $output = $this->rewrite_chunk($stream, substr($input, 0, $split), false);
            // Recreate the rewriter from its cursor, as resume does; no other
            // in-memory matching state may be needed for the remaining bytes.
            $stream = CSSURLProcessor::create_for_streaming($mapping, $stream->get_reentrancy_cursor());
            $output .= $this->rewrite_chunk($stream, substr($input, $split), true);
            $this->assertSame($expected, $output, 'Split at byte ' . $split);
        }
    }

    /** The saved suffix must stay bounded as more of a large stylesheet passes through. */
    public function testLargeMinifiedCssKeepsOnlyOneUrlPrefixBetweenChunks(): void
    {
        $stream = CSSURLProcessor::create_for_streaming(['https://old.example' => 'https://new.example']);
        $input = str_repeat('.a{background:url(https://old.example/image.png)}', 10000);
        $output = '';
        $input_bytes = strlen($input);
        for ($offset = 0; $offset < $input_bytes; $offset += 4096) {
            $output .= $this->rewrite_chunk($stream, substr($input, $offset, 4096), false);
            $this->assertLessThan(2048, strlen(json_encode($stream->get_reentrancy_cursor())));
        }
        $output .= $this->rewrite_chunk($stream, '', true);
        $this->assertSame(str_replace('old.example', 'new.example', $input), $output);
    }

    /** Matching a site requires its URL prefix, not just its name somewhere in another URL. */
    public function testUnmappedUrlsRelativePathsAndCssBytesRemainUnchanged(): void
    {
        $input = '.a{background:url(../image.png)}'
            . '.b{background:url(data:image/png;base64,AAAB)}'
            . '.c{background:url(https://old.example:8080/image.png)}'
            . '.d{background:url(https://other.example/old.example/image.png)}';
        $stream = CSSURLProcessor::create_for_streaming(['https://old.example' => 'https://new.example']);
        $this->assertSame($input, $this->rewrite_chunk($stream, $input, true));
    }
    /** A loopback IP address and port must work as the target without a DNS hostname. */
    public function testTargetMayUseAnIpAddress(): void
    {
        $stream = CSSURLProcessor::create_for_streaming(['https://old.example' => 'http://127.0.0.1:8881']);
        $this->assertSame('.a{src:url(http://127.0.0.1:8881/font.woff2)}',
            $this->rewrite_chunk($stream, '.a{src:url(https://old.example/font.woff2)}', true));
    }
    /** Collects only the small chunks supplied by these assertions. */
    private function rewrite_chunk(CSSURLProcessor $stream, string $input, bool $last): string
    {
        $output = '';
        foreach ($stream->rewrite_chunk($input, $last) as $chunk) {
            $this->assertLessThanOrEqual(65536, strlen($chunk));
            $output .= $chunk;
        }
        return $output;
    }

}
