<?php

namespace WordPress\Reprint\Server;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Protocol errors, not HTML.

/** Shared WordPress code and one site's uploads, without sibling upload trees. */
class MultisiteFileSelection {

    /** @var array Source context from the WordPress bootstrap. */
    private $source;

    /**
     * @param array $source {
     *     Trusted remote paths.
     *
     *     @type int    $site_id Selected site ID.
     *     @type string $abspath WordPress root.
     *     @type string $content_dir Content directory.
     *     @type string $uploads_dir Selected uploads directory.
     *     @type string $exporter_dir Reprint plugin directory, omitted from the clone.
     * }
     */
    public function __construct(array $source)
    {
        $this->source = $source;
    }

    /** Binds file cursors to this site's paths, including an empty completed cursor. */
    public function get_identity(): string
    {
        return hash('sha256', serialize([
            'files-v1', $this->source['site_id'], $this->source['abspath'],
            $this->source['content_dir'], $this->source['uploads_dir'], $this->source['exporter_dir'],
        ]));
    }

    /** Whether a path may be indexed or fetched for this selected site. */
    public function includes_path(string $remote_absolute_path): bool
    {
        if (normalize_path($remote_absolute_path) !== $remote_absolute_path) {
            return false;
        }
        $source = $this->source;
        if (path_is_same_as_or_descendant_of($remote_absolute_path, $source['exporter_dir'])) {
            return false;
        }
        foreach (['wp-admin', 'wp-includes'] as $directory) {
            if (path_is_same_as_or_descendant_of($remote_absolute_path, $source['abspath'] . '/' . $directory)) {
                return true;
            }
        }
        foreach (['plugins', 'themes', 'mu-plugins', 'languages'] as $directory) {
            if (path_is_same_as_or_descendant_of($remote_absolute_path, $source['content_dir'] . '/' . $directory)) {
                return true;
            }
        }
        // Parent directories lead traversal to the selected upload root. For
        // site 1 the uploads root also contains every sibling's sites/ tree.
        if ( (int) $source['site_id'] === 1 && path_is_same_as_or_descendant_of(
            $remote_absolute_path, $source['uploads_dir'] . '/sites'
        )) {
            return false;
        }
        if (path_is_same_as_or_descendant_of($remote_absolute_path, $source['uploads_dir'])) {
            return true;
        }
        if (path_is_same_as_or_descendant_of($source['uploads_dir'], $remote_absolute_path)
            && path_is_same_as_or_descendant_of($remote_absolute_path, $source['abspath'])) {
            return true;
        }

        // The target receives a fresh wp-config.php; source credentials and
        // custom bootstrap includes must not become the target configuration.
        $core_files = [
            'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
            'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php',
            'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php',
            'xmlrpc.php', 'license.txt', 'readme.html',
        ];
        return dirname($remote_absolute_path) === $source['abspath']
            && in_array(basename($remote_absolute_path), $core_files, true);
    }

    /** Rejects direct requests, resumed paths, and links which could widen the selection. */
    public function assert_path_allowed(string $remote_absolute_path): void
    {
        if (!$this->includes_path($remote_absolute_path)) {
            throw new \InvalidArgumentException('Path is outside the selected multisite site: ' . $remote_absolute_path);
        }
        clearstatcache(true, $remote_absolute_path);
        $resolved_absolute_path = realpath($remote_absolute_path);
        if (is_link($remote_absolute_path) || ( $resolved_absolute_path !== false && $resolved_absolute_path !== $remote_absolute_path )) {
            throw new \InvalidArgumentException('Symlinks require a separate multisite migration rule: ' . $remote_absolute_path);
        }
    }
}
