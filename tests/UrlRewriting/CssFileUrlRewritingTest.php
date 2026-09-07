<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\CSSURLProcessor;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class CssFileUrlRewritingTest extends TestCase {

    /** Every split includes positions inside the scheme, host, path, and boundary. */
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
            // A new process receives only the state saved at the part boundary.
            $stream = CSSURLProcessor::create_for_streaming($mapping, $stream->get_reentrancy_cursor());
            $output .= $this->rewrite_chunk($stream, substr($input, $split), true);
            $this->assertSame($expected, $output, 'Split at byte ' . $split);
        }
    }

    /** A stylesheet larger than one chunk cannot grow the retained tail. */
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

    /** An unrelated URL must not change because its path contains the source host. */
    public function testUnmappedUrlsRelativePathsAndCssBytesRemainUnchanged(): void
    {
        $input = '.a{background:url(../image.png)}'
            . '.b{background:url(data:image/png;base64,AAAB)}'
            . '.c{background:url(https://old.example:8080/image.png)}'
            . '.d{background:url(https://other.example/old.example/image.png)}';
        $stream = CSSURLProcessor::create_for_streaming(['https://old.example' => 'https://new.example']);
        $this->assertSame($input, $this->rewrite_chunk($stream, $input, true));
    }
    /** Local servers may be addressed by IP rather than a DNS name. */
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
