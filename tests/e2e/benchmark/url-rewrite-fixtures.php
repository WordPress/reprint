<?php

use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;

const REPRINT_URL_REWRITE_BENCHMARK_CASES = [
    'html',
    'style-elements',
    'blocks-literal-urls',
    'blocks-nested-html',
    'blocks-repeated-urls',
    'blocks-escaped-quotes',
    'blocks-encoded-shortcodes',
    'blocks-no-source-urls',
    'serialized-options',
];

/**
 * Build one post or option. IDs vary both the value and its URLs so whole-value
 * cache hits cannot hide parsing costs. The repeated-URL case varies only text.
 *
 * @return array {
 *     @type string       $input    Source database value.
 *     @type string|array $expected Target text, or decoded attributes in block order.
 * }
 */
function reprint_url_rewrite_benchmark_fixture(string $scenario, int $row): array
{
    $input = '';
    $expected = strpos($scenario, 'blocks-') === 0 ? [] : '';
    $source_values = [];
    $target_values = [];
    for ($block = 0; $block < 32; ++$block) {
        $id = $row * 32 + $block;
        $url_id = $scenario === 'blocks-repeated-urls' ? 0 : $id;
        $source = 'https://source.example/article/' . $url_id;
        $target = 'https://destination.example/article/' . $url_id;
        $source_html = '<p>Article ' . $id . ' <a href="' . $source . '">Read</a></p>';
        $target_html = '<p>Article ' . $id . ' <a href="' . $target . '">Read</a></p>';

        switch ($scenario) {
            case 'html':
                $input .= $source_html;
                $expected .= $target_html;
                continue 2;
            case 'style-elements':
                $input .= '<style>.article-' . $id . '{background:url("' . $source . '")}</style>';
                $expected .= '<style>.article-' . $id . '{background:url("' . $target . '")}</style>';
                continue 2;
            case 'serialized-options':
                $source_values[$id] = ['url' => $source, 'label' => 'Article ' . $id];
                $target_values[$id] = ['url' => $target, 'label' => 'Article ' . $id];
                continue 2;
            case 'blocks-literal-urls':
                $source_value = $source;
                $target_value = $target;
                break;
            case 'blocks-nested-html':
            case 'blocks-repeated-urls':
            case 'blocks-escaped-quotes':
                $source_value = $source_html;
                $target_value = $target_html;
                break;
            case 'blocks-encoded-shortcodes':
                $source_value = '[vc_raw_html]' . base64_encode($source_html) . '[/vc_raw_html]';
                $target_value = '[vc_raw_html]' . base64_encode($target_html) . '[/vc_raw_html]';
                break;
            case 'blocks-no-source-urls':
                $source_value = 'Article ' . $id . ': plain text with no source URL.';
                $target_value = $source_value;
                break;
            default:
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI error text, not HTML output.
                throw new InvalidArgumentException('Unknown URL benchmark case: ' . $scenario);
        }

        // Divi stores content inside module.content.value. Keep ordinary settings
        // beside it: the removed fast path scanned these strings too.
        $attributes = [
            'module' => [
                'content' => ['value' => $source_value],
                'settings' => ['label' => 'Article ' . $id, 'color' => '#123456', 'spacing' => '16px'],
            ],
        ];
        $flags = $scenario === 'blocks-escaped-quotes' ? JSON_HEX_QUOT : 0;
        $input .= '<!-- wp:divi/text ' . json_encode($attributes, $flags) . ' /-->';
        $attributes['module']['content']['value'] = $target_value;
        $expected[] = $attributes;
    }

    if ($scenario === 'serialized-options') {
        $input = serialize($source_values);
        $expected = serialize($target_values);
    }
    return ['input' => $input, 'expected' => $expected];
}

/**
 * Reject skipped rewrites, lost text and duplicate blocks before reporting speed.
 * Block JSON may change whitespace or escaping; its decoded values must match.
 *
 * @param string|array $expected Target text, or decoded attributes in block order.
 */
function reprint_check_url_rewrite_benchmark_output(string $output, $expected): void
{
    $actual = $output;
    if (is_array($expected)) {
        $actual = [];
        $parser = new BlockMarkupProcessor($output);
        while ($parser->next_token()) {
            if ($parser->get_token_type() !== '#block-comment' || $parser->get_block_name() !== 'wp:divi/text') {
                throw new RuntimeException('The URL benchmark output contains an unexpected token.');
            }
            $actual[] = $parser->get_block_attributes();
        }
    }
    if ($actual !== $expected) {
        throw new RuntimeException('The URL benchmark output does not match the expected target content.');
    }
}
