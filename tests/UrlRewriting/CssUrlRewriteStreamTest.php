<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class CssUrlRewriteStreamTest extends TestCase {

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
            ['http://old.example/local/styles/', 'http://old.example/local/photo', '//old.example/local/font', 'http:\\/\\/old.example\\/local\\/photo'],
            $input
        );
        $mapping = [
            'https://old.example' => 'http://old.example/local',
            'https://old.example/assets' => 'http://old.example/local/styles',
        ];
        $input_bytes = strlen($input);
        for ($split = 0; $split <= $input_bytes; ++$split) {
            $stream = new CssUrlRewriteStream($mapping);
            $output = $stream->rewrite_chunk(substr($input, 0, $split), false);
            // A new process receives only the state saved at the part boundary.
            $stream = new CssUrlRewriteStream($mapping, $stream->get_cursor());
            $output .= $stream->rewrite_chunk(substr($input, $split), true);
            $this->assertSame($expected, $output, 'Split at byte ' . $split);
        }
    }

    public function testLargeMinifiedCssKeepsOnlyOneUrlPrefixBetweenChunks(): void
    {
        $stream = new CssUrlRewriteStream(['https://old.example' => 'https://new.example']);
        $input = str_repeat('.a{background:url(https://old.example/image.png)}', 10000);
        $output = '';
        $input_bytes = strlen($input);
        for ($offset = 0; $offset < $input_bytes; $offset += 4096) {
            $output .= $stream->rewrite_chunk(substr($input, $offset, 4096), false);
            $this->assertLessThan(128, strlen(json_encode($stream->get_cursor())));
        }
        $output .= $stream->rewrite_chunk('', true);
        $this->assertSame(str_replace('old.example', 'new.example', $input), $output);
    }

    public function testUnmappedUrlsRelativePathsAndCssBytesRemainUnchanged(): void
    {
        $input = '.a{background:url(../image.png)}'
            . '.b{background:url(data:image/png;base64,AAAB)}'
            . '.c{background:url(https://old.example:8080/image.png)}'
            . '.d{background:url(https://other.example/old.example/image.png)}';
        $stream = new CssUrlRewriteStream(['https://old.example' => 'https://new.example']);
        $this->assertSame($input, $stream->rewrite_chunk($input, true));
    }
}
