<?php
/**
 * Playground uploads-proxy mu-plugin source generator.
 *
 * The wizard ships this code to Playground as
 * /wordpress/wp-content/mu-plugins/0-reprint-playground-glue.php
 * during activation. The proxy streams missing /wp-content/uploads/*
 * from the source site so freshly imported posts render before the
 * full uploads tree has been pulled.
 *
 * The streaming logic is kept here as a regular function (not embedded
 * directly in the heredoc) so it can be unit-tested against a real
 * fake source HTTP server without spinning up Playground.
 */

if (!function_exists('reprint_playground_uploads_proxy_code')) {
    /**
     * Returns the full mu-plugin source. The wizard writes this string
     * verbatim to /wordpress/wp-content/mu-plugins/0-reprint-playground-glue.php.
     */
    function reprint_playground_uploads_proxy_code(string $source_origin): string {
        $handler_source = file_get_contents(__DIR__ . '/playground-uploads-proxy-handler.php');
        if ($handler_source === false) {
            $handler_source = '';
        }
        // Strip the opening <?php so we can inline the file contents
        // into the heredoc without nesting two opening tags.
        $handler_source = preg_replace('/^<\?php\s*/', '', $handler_source, 1);

        $source_origin_php = var_export($source_origin, true);

        return <<<PHP
<?php
// Reprint Playground glue — installed by /reprint.php?action=blueprint.
//
// Earlier we filtered option_home / option_siteurl down to scheme://host
// to dodge a doubled /scope:<slug>/ in asset URLs — but stripping the
// scope from home_url() also strips it from <a href> links, so every
// click navigates out of the iframe scope and lands on Playground's
// "Page not found" page. The Atomic-versioned-plugin layout fix in
// the activation step already collapses the path mismatch that was
// behind the visible doubling, so we leave WP's URL output alone now.

// page-optimize is deactivated at db-apply time by reprint's
// deactivate_path_incompatible_plugins() — its concat-css/js URL
// builder doubles Playground's /scope:<slug>/ prefix into hrefs
// that 404. Belt-and-braces: also disable Jetpack's frontend CSS
// imploder (same family of asset bundling that needs Atomic-side
// route handlers).
add_filter('jetpack_implode_frontend_css', '__return_false');
add_filter('jetpack_force_disable_site_accelerator', '__return_true');

// Stream missing uploads from the source so media keeps rendering
// until reprint files pull --filter=skipped-earlier downloads them.
//
// We PROXY here, not 302 to the source. Playground's service worker
// post-processes Location headers to keep the iframe in scope, so a
// Location pointing at https://adamadam.blog/wp-content/... comes
// back as https://adamadam.blog/scope:foo/wp-content/...; the
// browser follows it, the SW re-intercepts because the path still
// contains /wp-content/uploads/, this hook fires again, and we 302
// in a loop until ERR_TOO_MANY_REDIRECTS.
//
// Streaming the body through PHP — chunk by chunk via
// CURLOPT_WRITEFUNCTION — keeps memory bounded for big media (the
// browser sees a single same-origin response with the image bytes,
// no redirect for the SW to mangle, and we never load a 100MB video
// into PHP memory before the first byte hits the wire).

{$handler_source}

\$reprint_source_origin = $source_origin_php;
if (\$reprint_source_origin !== '') {
    add_action('init', function () use (\$reprint_source_origin) {
        reprint_playground_uploads_proxy_handle_request(\$reprint_source_origin);
    }, 0);
}
PHP;
    }
}
