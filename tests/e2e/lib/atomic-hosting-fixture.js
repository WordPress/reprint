/**
 * Shared fixture: a realistic WP.com Atomic / managed-hosting layout where WP
 * core, plugins, themes, drop-ins, and mu-plugins live in a shared, read-only
 * directory and are symlinked into the site root.
 *
 * Used by the preserve-local tests (import-37) and the --include/--remap managed
 * composition test (import-51).
 *
 * Layout produced (relative to `siteRoot`):
 *
 *   <siteRoot>/
 *     __wp__                     -> ../wordpress/core/latest
 *     wp-load.php                -> __wp__/wp-load.php
 *     wp-content/
 *       object-cache.php         -> ../../wordpress/drop-ins/object-cache.php
 *       advanced-cache.php       -> ../../wordpress/drop-ins/advanced-cache.php
 *       mu-plugins/
 *         wpcomsh               -> ../../../wordpress/plugins/wpcomsh/latest
 *         wpcomsh-loader.php    -> ../../../wordpress/plugins/wpcomsh/latest/wpcomsh-loader.php
 *       plugins/
 *         akismet               -> ../../../wordpress/plugins/akismet/latest
 *         jetpack               -> ../../../wordpress/plugins/jetpack/latest
 *       themes/
 *         twentyseventeen       -> ../../../wordpress/themes/twentyseventeen/latest
 *
 * The shared `wordpress/` tree is created as a sibling of `siteRoot` (so the
 * hardcoded relative symlinks resolve regardless of how deep `siteRoot` is) and
 * locked to 0555 to mirror real hosting's read-only managed layer.
 */
import { existsSync, writeFileSync, mkdirSync, symlinkSync, chmodSync } from 'node:fs';
import { join, dirname } from 'node:path';

/**
 * Build the managed hosting structure at an absolute `siteRoot`.
 *
 * @param {string} siteRoot Absolute path the site root lives at (the docroot).
 * @returns {{ siteRoot: string, wpShared: string }}
 */
export function buildAtomicHostingFixture(siteRoot) {
    // Shared tree is a sibling of the site root — keeps the relative symlinks
    // below valid no matter how deep siteRoot is.
    const wpShared = join(dirname(siteRoot), 'wordpress');

    // -- shared wordpress directory (read-only after setup) --------
    const dirs = [
        'core/latest/wp-includes',
        'drop-ins',
        'plugins/akismet/latest',
        'plugins/jetpack/latest',
        'plugins/wpcomsh/latest',
        'themes/twentyseventeen/latest',
    ];
    for (const d of dirs) {
        mkdirSync(join(wpShared, d), { recursive: true });
    }
    writeFileSync(join(wpShared, 'core/latest/wp-load.php'),
        '<?php // shared WP core loader');
    writeFileSync(join(wpShared, 'core/latest/wp-includes/version.php'),
        '<?php $wp_version = "6.7";');
    writeFileSync(join(wpShared, 'drop-ins/object-cache.php'),
        '<?php // shared object cache drop-in');
    writeFileSync(join(wpShared, 'drop-ins/advanced-cache.php'),
        '<?php // shared advanced cache drop-in');
    writeFileSync(join(wpShared, 'plugins/akismet/latest/akismet.php'),
        '<?php // shared akismet');
    writeFileSync(join(wpShared, 'plugins/jetpack/latest/jetpack.php'),
        '<?php // shared jetpack');
    writeFileSync(join(wpShared, 'plugins/wpcomsh/latest/wpcomsh-loader.php'),
        '<?php // shared wpcomsh loader');
    writeFileSync(join(wpShared, 'themes/twentyseventeen/latest/style.css'),
        '/* shared twentyseventeen */');

    // -- site root with hosting symlinks ---------------------------
    mkdirSync(join(siteRoot, 'wp-content', 'mu-plugins'), { recursive: true });
    mkdirSync(join(siteRoot, 'wp-content', 'plugins'), { recursive: true });
    mkdirSync(join(siteRoot, 'wp-content', 'themes'), { recursive: true });

    // Directory symlink to WP core
    symlinkSync('../wordpress/core/latest',
        join(siteRoot, '__wp__'));

    // File symlink through __wp__
    symlinkSync('__wp__/wp-load.php',
        join(siteRoot, 'wp-load.php'));

    // Drop-in file symlinks (2 levels up from wp-content/ to site parent)
    symlinkSync('../../wordpress/drop-ins/object-cache.php',
        join(siteRoot, 'wp-content', 'object-cache.php'));
    symlinkSync('../../wordpress/drop-ins/advanced-cache.php',
        join(siteRoot, 'wp-content', 'advanced-cache.php'));

    // mu-plugins: directory symlink + file symlink (3 levels up)
    symlinkSync('../../../wordpress/plugins/wpcomsh/latest',
        join(siteRoot, 'wp-content', 'mu-plugins', 'wpcomsh'));
    symlinkSync('../../../wordpress/plugins/wpcomsh/latest/wpcomsh-loader.php',
        join(siteRoot, 'wp-content', 'mu-plugins', 'wpcomsh-loader.php'));

    // Plugin directory symlinks (3 levels up)
    symlinkSync('../../../wordpress/plugins/akismet/latest',
        join(siteRoot, 'wp-content', 'plugins', 'akismet'));
    symlinkSync('../../../wordpress/plugins/jetpack/latest',
        join(siteRoot, 'wp-content', 'plugins', 'jetpack'));

    // Theme directory symlink (3 levels up)
    symlinkSync('../../../wordpress/themes/twentyseventeen/latest',
        join(siteRoot, 'wp-content', 'themes', 'twentyseventeen'));

    // Lock down the shared directory — hosting infra is read-only
    chmodSync(join(wpShared, 'core/latest/wp-includes'), 0o555);
    chmodSync(join(wpShared, 'core/latest'), 0o555);
    chmodSync(join(wpShared, 'drop-ins'), 0o555);
    chmodSync(join(wpShared, 'plugins/akismet/latest'), 0o555);
    chmodSync(join(wpShared, 'plugins/jetpack/latest'), 0o555);
    chmodSync(join(wpShared, 'plugins/wpcomsh/latest'), 0o555);
    chmodSync(join(wpShared, 'themes/twentyseventeen/latest'), 0o555);

    return { siteRoot, wpShared };
}

// Restore write permissions so cleanup can remove the tree.
export function unlockSharedDir(wpShared) {
    if (!existsSync(wpShared)) return;
    const unlock = [
        'core/latest/wp-includes',
        'core/latest',
        'drop-ins',
        'plugins/akismet/latest',
        'plugins/jetpack/latest',
        'plugins/wpcomsh/latest',
        'themes/twentyseventeen/latest',
    ];
    for (const d of unlock) {
        const p = join(wpShared, d);
        if (existsSync(p)) chmodSync(p, 0o755);
    }
}
