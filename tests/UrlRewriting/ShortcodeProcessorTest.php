<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\Shortcode\ShortcodeProcessor;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class ShortcodeProcessorTest extends TestCase {
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
