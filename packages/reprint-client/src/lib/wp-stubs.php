<?php
/**
 * Minimal WordPress function stubs for wp-php-toolkit/data-liberation
 * to work outside of a WordPress environment.
 *
 * These stubs provide just enough compatibility for StructuredBlockMarkupUrlProcessor
 * and wp_rewrite_urls() to function correctly.
 */

// Load HTML5 named character references (global variable, not autoloaded).
// Required by WP_HTML_Decoder for parsing HTML entities like &quot;.
$_html5_ncr_file = null;
foreach ([4, 5, 2] as $_html5_ncr_levels) {
    $_html5_ncr_candidate = dirname(__DIR__, $_html5_ncr_levels) .
        '/vendor/wp-php-toolkit/html/html5-named-character-references.php';
    if (file_exists($_html5_ncr_candidate)) {
        $_html5_ncr_file = $_html5_ncr_candidate;
        break;
    }
}
if ($_html5_ncr_file !== null) {
    require_once $_html5_ncr_file;
}
unset($_html5_ncr_candidate, $_html5_ncr_file, $_html5_ncr_levels);

if (!function_exists('apply_filters')) {
    /**
     * Stub: returns the first argument unchanged (no filters registered).
     */
    function apply_filters($hook_name, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('wp_kses_uri_attributes')) {
    /**
     * Stub: returns the list of HTML attributes that contain URIs.
     * Matches WordPress core's wp_kses_uri_attributes().
     */
    function wp_kses_uri_attributes() {
        return [
            'action',
            'cite',
            'classid',
            'codebase',
            'data',
            'formaction',
            'href',
            'icon',
            'longdesc',
            'manifest',
            'poster',
            'profile',
            'src',
            'usemap',
            'xmlns',
        ];
    }
}

if (!function_exists('esc_url')) {
    /**
     * Escape a decoded URL for the HTML tag processor's attribute setter.
     * URL parsing happens before this call. Quotes in a valid URL must become
     * entities here, or they can end the surrounding HTML attribute.
     */
    function esc_url($url, $protocols = null, $_context = 'display') {
        if (empty($url)) {
            return '';
        }
        $url = str_replace(' ', '%20', $url);
        return $_context === 'display'
            ? htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : $url;
    }
}

if (!function_exists('esc_attr')) {
    /**
     * Stub: escapes string for safe use in an HTML attribute.
     */
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if(!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct($code, $message, $data = []) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
    }
}
