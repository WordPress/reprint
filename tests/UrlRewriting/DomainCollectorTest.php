<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class DomainCollectorTest extends TestCase
{
    // --- Plain URL strings ---

    public function testCollectsFromWholeUrlString(): void
    {
        $collector = new DomainCollector();
        $collector->scan('https://example.com/page');
        $domains = $collector->get_domains();
        $this->assertCount(1, $domains);
        $this->assertEquals('https://example.com', $domains[0]);
    }

    public function testCollectsHttpUrl(): void
    {
        $collector = new DomainCollector();
        $collector->scan('http://old-site.org/about');
        $domains = $collector->get_domains();
        $this->assertCount(1, $domains);
        $this->assertEquals('http://old-site.org', $domains[0]);
    }

    public function testIgnoresProseWithEmbeddedUrls(): void
    {
        $collector = new DomainCollector();
        $collector->scan('Visit https://example.com/page for details.');
        $this->assertCount(0, $collector->get_domains());
    }

    public function testCollectsUrlWithPort(): void
    {
        $collector = new DomainCollector();
        $collector->scan('http://localhost:8080/api');
        $domains = $collector->get_domains();
        $this->assertContains('http://localhost:8080', $domains);
    }

    public function testEmptyStringReturnsNoDomains(): void
    {
        $collector = new DomainCollector();
        $collector->scan('');
        $this->assertCount(0, $collector->get_domains());
    }

    public function testNoUrlsReturnsNoDomains(): void
    {
        $collector = new DomainCollector();
        $collector->scan('This is just plain text with no URLs.');
        $this->assertCount(0, $collector->get_domains());
    }

    // --- HTML media tags ---

    public function testCollectsFromImgSrcNotAnchorHref(): void
    {
        $collector = new DomainCollector();
        $collector->scan('<a href="https://wp.org/plugins">Plugins</a> <img src="https://cdn.wp.org/img.jpg"/>');
        $domains = $collector->get_domains();
        $this->assertNotContains('https://wp.org', $domains);
        $this->assertContains('https://cdn.wp.org', $domains);
    }

    public function testCollectsFromVideoAndAudioTags(): void
    {
        $collector = new DomainCollector();
        $collector->scan(
            '<video src="https://media.example.com/video.mp4" poster="https://cdn.example.com/thumb.jpg"></video>' .
            '<audio src="https://audio.example.com/track.mp3"></audio>'
        );
        $domains = $collector->get_domains();
        $this->assertContains('https://media.example.com', $domains);
        $this->assertContains('https://cdn.example.com', $domains);
        $this->assertContains('https://audio.example.com', $domains);
    }

    public function testSkipsLinkScriptIframeTags(): void
    {
        $collector = new DomainCollector();
        $collector->scan(
            '<link rel="stylesheet" href="https://fonts.googleapis.com/css">' .
            '<script src="https://cdn.analytics.com/tracker.js"></script>' .
            '<iframe src="https://embed.example.com/widget"></iframe>' .
            '<img src="https://mysite.com/logo.png"/>'
        );
        $domains = $collector->get_domains();
        $this->assertNotContains('https://fonts.googleapis.com', $domains);
        $this->assertNotContains('https://cdn.analytics.com', $domains);
        $this->assertNotContains('https://embed.example.com', $domains);
        $this->assertContains('https://mysite.com', $domains);
    }

    public function testSkipsUrlsInHtmlTextNodes(): void
    {
        $collector = new DomainCollector();
        $collector->scan('<p>Check out https://twitter.com/user for updates</p>');
        $this->assertCount(0, $collector->get_domains());
    }

    // --- WordPress blocks ---

    public function testCollectsFromMediaBlocks(): void
    {
        $collector = new DomainCollector();
        $collector->scan(
            '<!-- wp:image {"url":"https://media.example.com/photo.jpg"} -->' .
            '<figure><img src="https://media.example.com/photo.jpg"/></figure>' .
            '<!-- /wp:image -->'
        );
        $domains = $collector->get_domains();
        $this->assertContains('https://media.example.com', $domains);
    }

    public function testSkipsNavigationBlocks(): void
    {
        $collector = new DomainCollector();
        $collector->scan(
            '<!-- wp:navigation-link {"url":"https://external-site.com/page"} /-->' .
            '<!-- wp:image {"url":"https://mycdn.com/img.jpg"} /-->'
        );
        $domains = $collector->get_domains();
        $this->assertNotContains('https://external-site.com', $domains);
        $this->assertContains('https://mycdn.com', $domains);
    }

    // --- Serialized PHP ---

    public function testCollectsUrlsFromSerializedPhp(): void
    {
        $collector = new DomainCollector();
        // a:1:{s:4:"home";s:24:"https://mysite.com/blog";}
        $collector->scan(serialize(['home' => 'https://mysite.com/blog']));
        $domains = $collector->get_domains();
        $this->assertContains('https://mysite.com', $domains);
    }

    public function testSkipsNonUrlStringsInSerializedPhp(): void
    {
        $collector = new DomainCollector();
        // The string value "hello world" is not a URL, so no domains collected
        $collector->scan(serialize(['greeting' => 'hello world']));
        $this->assertCount(0, $collector->get_domains());
    }

    public function testSerializedPhpSkipsExternalLinksInHtml(): void
    {
        $collector = new DomainCollector();
        // Post content stored in serialized PHP: the <a href> should be skipped,
        // the <img src> should be collected
        $html = '<a href="https://twitter.com/user">Follow me</a><img src="https://mycdn.com/photo.jpg"/>';
        $collector->scan(serialize(['post_content' => $html]));
        $domains = $collector->get_domains();
        $this->assertNotContains('https://twitter.com', $domains);
        $this->assertContains('https://mycdn.com', $domains);
    }

    // --- JSON ---

    public function testCollectsUrlsFromJson(): void
    {
        $collector = new DomainCollector();
        $collector->scan(json_encode(['siteurl' => 'https://mysite.com']));
        $domains = $collector->get_domains();
        $this->assertContains('https://mysite.com', $domains);
    }

    public function testCollectsUrlsFromNestedJson(): void
    {
        $collector = new DomainCollector();
        $collector->scan(json_encode([
            'settings' => [
                'logo' => 'https://cdn.example.com/logo.png',
                'name' => 'My Site',
            ],
        ]));
        $domains = $collector->get_domains();
        $this->assertContains('https://cdn.example.com', $domains);
    }

    public function testSkipsNonUrlStringsInJson(): void
    {
        $collector = new DomainCollector();
        $collector->scan(json_encode(['title' => 'Hello World', 'count' => 42]));
        $this->assertCount(0, $collector->get_domains());
    }

    // --- Cross-format: deduplication, merging, ordering ---

    public function testDeduplicatesDomains(): void
    {
        $collector = new DomainCollector();
        $collector->scan(json_encode([
            'url1' => 'https://example.com/page1',
            'url2' => 'https://example.com/page2',
        ]));
        $domains = $collector->get_domains();
        $this->assertCount(1, $domains);
        $this->assertEquals('https://example.com', $domains[0]);
    }

    public function testCollectsDomainsAcrossMultipleScans(): void
    {
        $collector = new DomainCollector();
        $collector->scan('https://first.com/a');
        $collector->scan('https://second.com/b');
        $domains = $collector->get_domains();
        $this->assertCount(2, $domains);
        $this->assertContains('https://first.com', $domains);
        $this->assertContains('https://second.com', $domains);
    }

    public function testMergeWithPreviousList(): void
    {
        $collector = new DomainCollector();
        $collector->scan('https://new-discovery.com/page');
        $collector->merge(['https://previously-found.com', 'https://another.com']);
        $domains = $collector->get_domains();
        $this->assertCount(3, $domains);
        $this->assertContains('https://new-discovery.com', $domains);
        $this->assertContains('https://previously-found.com', $domains);
        $this->assertContains('https://another.com', $domains);
    }

    public function testReturnsSortedList(): void
    {
        $collector = new DomainCollector();
        $collector->scan(json_encode([
            'z' => 'https://zebra.com/page',
            'a' => 'https://apple.com/page',
            'm' => 'https://mango.com/page',
        ]));
        $domains = $collector->get_domains();
        $this->assertEquals(['https://apple.com', 'https://mango.com', 'https://zebra.com'], $domains);
    }

    public function testDifferentProtocolsSameDomainAreSeparate(): void
    {
        $collector = new DomainCollector();
        $collector->scan('http://example.com/a');
        $collector->scan('https://example.com/b');
        $domains = $collector->get_domains();
        $this->assertCount(2, $domains);
        $this->assertContains('http://example.com', $domains);
        $this->assertContains('https://example.com', $domains);
    }
}
