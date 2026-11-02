# Prepare a hosting destination

Use this guide after copying the site's files and database. Your platform is
responsible for the destination web server, PHP extensions, database credentials,
and `wp-config.php`. Reprint does not switch DNS or merge pre-existing target data.

Use a dedicated destination database. An import can replace tables with the
source prefix; unrelated old tables with a different prefix can remain. Do not
assume that an import emptied the entire database.

If your platform runs Reprint programmatically, start with
[Build with Reprint](https://developer.wordpress.org/reprint/build-with-reprint/). For generated PHP or nginx
configuration, see [Runtime configuration](https://developer.wordpress.org/reprint/reference/runtime/).

## Run destination cleanup

Once the destination has its files, database, and working `wp-config.php`, run
all post-migration tasks with:

```sh
php reprint.phar post-process --fs-root=/path/to/wordpress --state-dir=/path/to/migration-state
```

Run only selected tasks with `--tasks`:

```sh
php reprint.phar post-process --fs-root=/path/to/wordpress --tasks=disable-failing-plugins
php reprint.phar post-process --fs-root=/path/to/wordpress --state-dir=/path/to/migration-state \
  --tasks=disable-hosting-plugins
```

Omitting `--tasks`, or passing `--tasks=all`, runs both tasks. A comma-separated
list selects only those tasks. Hosting cleanup runs first and startup recovery
runs second, regardless of the list's order. Processing stops at the first
failure. A theme or must-use plugin fatal during startup therefore cannot
prevent the earlier hosting cleanup.

`disable-hosting-plugins` removes known source-host plugin, MU-plugin, and
drop-in files using the same rules as `apply-runtime`. It uses the source-host
information saved in `--state-dir`. It does not contact the source, start
WordPress, change which plugins WordPress lists as active, or generate server
configuration. The saved download selection stays unchanged.
Generic cache drop-ins are removed only when the saved source-host data calls
for that. Finish an interrupted files-push, or finish or abort an interrupted
files-pull, before running this task.

Use the ready-to-run WordPress root containing `wp-load.php` as `--fs-root`,
not the raw download directory. Hosting cleanup uses the standard `wp-content`
layout under that root. If the state directory contains several remotes, pass
the original source URL to select one:

```sh
php reprint.phar post-process https://source.example/?reprint-api \
  --fs-root=/path/to/wordpress --state-dir=/path/to/migration-state
```

Post-processing does not uninstall Reprint. Use WordPress's plugin uninstall
flow when Reprint is no longer needed so the plugin can clean up its settings.

`disable-failing-plugins` performs the startup recovery described below. It
does not need migration state when run alone. JSON output has `status` and a
`results` list with each attempted task's name, status, and removed paths or
disabled plugins. Earlier results remain in the report if a later task fails.
Exit code 0 means all selected tasks completed; exit code 1 means processing
stopped. These tasks do not check page rendering or the web server.

## Check WordPress startup

Once the destination has its files, database, and working `wp-config.php`, run:

```sh
php reprint.phar recover --fs-root=/path/to/wordpress
```

The command tries to start WordPress in a separate PHP process. If startup
fails in a file belonging to one active regular plugin, it deactivates that
plugin and tries again. It keeps the plugin's files and data, without running
the plugin's usual deactivation code. Other failures stop the command.
Multisite plugins are not deactivated.

The JSON result lists the disabled plugins and their errors. Exit code 0 means
WordPress started; exit code 1 means the check could not complete. The command
uses the same PHP binary as Reprint. It does not check page rendering or the
web server, and disabling a plugin may remove features from the site.
No remote URL, connection token, or state directory is required.

## Verify the destination

These commands report startup and cleanup results, not whether the site is
ready for visitors. Check rendered pages, media, login, forms, permalink rules,
PHP and database compatibility, and the site's external service connections.
Review plugins disabled during recovery; their features are no longer running.

Keep downloaded copies and migration state private. For source-host adapters,
PHP embedding, managed keys, and private push storage, consult the
[plugin source guide](https://github.com/WordPress/reprint/blob/trunk/reprint-server-wp/README.md).
