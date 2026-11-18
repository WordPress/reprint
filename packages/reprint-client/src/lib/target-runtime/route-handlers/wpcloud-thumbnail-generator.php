<?php
/**
 * WP Cloud thumbnail generator — route handler implementation.
 *
 * Returns PHP code (as a string) that generates missing WordPress
 * thumbnail sizes on-the-fly using GD. This is a WP Cloud-specific
 * concern: wpcom exports only ship original-size uploads, but WordPress
 * post meta references sized variants like image-768x768.jpeg.
 *
 * The generated code is a self-contained IIFE that:
 * - Checks $_SERVER['REQUEST_URI'] for thumbnail-pattern URLs
 * - Requires WP_CONTENT_DIR to be defined (by the bootstrap)
 * - Accepts fixed square bounds and their aspect-ratio variants only
 * - Generates the resized image, caches it on disk, serves it
 * - Calls exit() — no need to boot WordPress
 * - Returns silently if the request doesn't match
 */
function wpcloud_thumbnail_generator_code(): string
{
    return <<<'PHP'
/**
 * On-the-fly thumbnail generation for WordPress upload images.
 *
 * WordPress stores references to sized variants like image-768x768.jpeg in
 * post meta, but the exported site only has the original full-size file.
 * This intercepts requests for missing thumbnails inside /wp-content/uploads/,
 * generates a properly resized copy from the original using GD, caches it on
 * disk so subsequent requests are served directly, and exits before WordPress
 * boots — keeping it fast. Missing sizes outside the fixed bounds return 404.
 */
(function() {
	if (!defined('WP_CONTENT_DIR')) return;

	$uri = $_SERVER['REQUEST_URI'] ?? '';
	$path = parse_url($uri, PHP_URL_PATH);
	if (!$path) return;
	$path = rawurldecode($path);

	// uploads/../../../private/photo-2x2.png must not select an image outside
	// uploads. Check decoded segments before any filesystem calls, including
	// backslashes which Windows treats as directory separators.
	if (strpos($path, "\0") !== false || preg_match('#(?:^|/)\.\.(?:/|$)#', str_replace('\\', '/', $path))) return;

	// Only handle requests inside /wp-content/uploads/
	if (strpos($path, '/wp-content/uploads/') === false) return;

	// Only handle filenames with a -WxH thumbnail suffix
	if (!preg_match('/-(\d+)x(\d+)(\.\w+)$/', $path, $m)) return;

	$max_width  = (int) $m[1];
	$max_height = (int) $m[2];
	$ext        = strtolower($m[3]);

	// Map the URL path to a filesystem path relative to WP_CONTENT_DIR.
	$relative   = preg_replace('#^.*/wp-content/#', '', $path);
	$thumb_path = WP_CONTENT_DIR . '/' . $relative;

	// If the thumbnail already exists on disk, let the server handle it
	if (file_exists($thumb_path) || is_link($thumb_path)) return;

	// Keep one spelling per size and a fixed set of bounds. The runtime runs
	// before WordPress, so theme and plugin image-size registrations are unavailable.
	if ($max_width < 1 || $max_height < 1 || $m[1] !== (string) $max_width || $m[2] !== (string) $max_height ||
		!in_array(max($max_width, $max_height), [150, 300, 768, 1024, 1536, 2048], true)) {
		http_response_code(404);
		exit;
	}

	// Derive the original (unsized) file path
	$original_path = realpath(preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $thumb_path));
	$uploads_directory = realpath(WP_CONTENT_DIR . '/uploads');
	$thumbnail_directory = realpath(dirname($thumb_path));
	if ($original_path === false || $uploads_directory === false || $thumbnail_directory === false) return;

	// A link inside uploads can still point elsewhere. Both the source image
	// and the directory receiving the thumbnail must resolve inside uploads.
	$uploads_prefix = rtrim($uploads_directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	if (strpos($original_path, $uploads_prefix) !== 0) return;
	if ($thumbnail_directory !== $uploads_directory && strpos($thumbnail_directory, $uploads_prefix) !== 0) return;

	// Generated thumbnails must not become sources for another set of variants.
	if (preg_match('/-\d+x\d+(\.\w+)$/', $original_path)) {
		http_response_code(404);
		exit;
	}

	$original_dimensions = @getimagesize($original_path);
	if (!$original_dimensions || $original_dimensions[0] < 1 || $original_dimensions[1] < 1) return;

	// Compute the fitted dimensions for the selected square bound,
	// never enlarging beyond the original.
	$orig_w = $original_dimensions[0];
	$orig_h = $original_dimensions[1];
	$scale  = min(max($max_width, $max_height) / max($orig_w, $orig_h), 1.0);
	$new_w  = (int) round($orig_w * $scale);
	$new_h  = (int) round($orig_h * $scale);

	// Accept square bounds and the exact fitted dimensions only. Letting the
	// other dimension vary freely would create many names for the same image.
	if ($new_w < 1 || $new_h < 1 ||
		($max_width !== $max_height && ($max_width !== $new_w || $max_height !== $new_h))) {
		http_response_code(404);
		exit;
	}

	// Load the original image with GD
	$src = null;
	switch ($ext) {
		case '.jpg':
		case '.jpeg':
			$src = @imagecreatefromjpeg($original_path);
			break;
		case '.png':
			$src = @imagecreatefrompng($original_path);
			break;
		case '.gif':
			$src = @imagecreatefromgif($original_path);
			break;
		case '.webp':
			if (function_exists('imagecreatefromwebp')) {
				$src = @imagecreatefromwebp($original_path);
			}
			break;
	}
	if (!$src) return;

	$dst = imagecreatetruecolor($new_w, $new_h);

	// Preserve transparency for PNG and GIF
	if ($ext === '.png') {
		imagealphablending($dst, false);
		imagesavealpha($dst, true);
	} elseif ($ext === '.gif') {
		$transparent = imagecolortransparent($src);
		if ($transparent >= 0) {
			$color = imagecolorsforindex($src, $transparent);
			$new_transparent = imagecolorallocate($dst, $color['red'], $color['green'], $color['blue']);
			imagefill($dst, 0, 0, $new_transparent);
			imagecolortransparent($dst, $new_transparent);
		}
	}

	imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);

	// Cache the thumbnail to disk so future requests skip this code path
	$thumb_dir = dirname($thumb_path);
	if (!is_dir($thumb_dir)) {
		@mkdir($thumb_dir, 0755, true);
	}

	$content_type = 'application/octet-stream';
	switch ($ext) {
		case '.jpg':
		case '.jpeg':
			imagejpeg($dst, $thumb_path, 82);
			$content_type = 'image/jpeg';
			break;
		case '.png':
			imagepng($dst, $thumb_path);
			$content_type = 'image/png';
			break;
		case '.gif':
			imagegif($dst, $thumb_path);
			$content_type = 'image/gif';
			break;
		case '.webp':
			imagewebp($dst, $thumb_path, 82);
			$content_type = 'image/webp';
			break;
	}

	imagedestroy($src);
	imagedestroy($dst);

	// Serve the freshly generated thumbnail and stop
	header('Content-Type: ' . $content_type);
	header('Content-Length: ' . filesize($thumb_path));
	readfile($thumb_path);
	exit;
})();
PHP;
}
