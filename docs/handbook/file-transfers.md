# File transfer reference

Examples use `$URL`, `$STATE_DIR`, `$FS_ROOT`, and an authorized
`./reprint-key.pem` as described in [Conventions](https://developer.wordpress.org/reprint/reference/commands/#conventions).
Run [preflight](https://developer.wordpress.org/reprint/reference/preflight/) with the same source address and state directory
before downloading data.

This first builds a full index of the remote directory tree, then streams every file.
It can be interrupted and resumed at any time — just re-run the same command:

```bash
php reprint.phar files-pull "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT"
```

Read [Progress, results, and exit codes](https://developer.wordpress.org/reprint/reference/progress-and-results/) for
completion, continuation, and temporary-failure handling.

## File pull modes

`files-pull`, `pull-files`, and `pull` use `--mode=catch-up` by default.
Catch-up applies paths whose remote index entries changed since the previous
completed pull. A local edit remains when that remote path did not change, and
a path found only in the local tree remains in place.

Use mirror mode when paths inside today's pull selection must match today's
remote index:

```bash
php reprint.phar files-pull "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
    --mode=mirror
```

For example, suppose `wp-content/themes/example/style.css` still contains
`blue` remotely but contains `red` locally, and `debug.log` exists only
locally. Catch-up leaves both paths alone when the remote index did not change.
Mirror downloads the remote `style.css` and removes `debug.log` when both paths
are inside the current pull selection.

The normal path options define that selection. This includes `--include`,
`--exclude`, `--filter`, and `--remap`. Mirror does not change paths outside
the selection. `--on-fs-root-nonempty=preserve-local`
also keeps pre-existing local paths which were not recorded by the first pull.

File pulls always omit paths matched by the built-in default skip rules. These
rules cover known generated backup archives, cache, log, upgrade, and temporary
paths, plus version-control metadata, IDE and package-manager
caches, operating-system metadata, and editor scratch files. `--include`,
`--exclude`, `--filter`, and `--remap` cannot override these omissions.

`node_modules` directories are included by default because plugins and themes
may bundle runtime PHP, JavaScript, and CSS dependencies there. Use `--exclude`
to omit a specific directory when you know the site does not need it.

## Host platform plugins

New pulls keep host platform plugins, MU plugins, and host cache drop-ins.
Reprint does not infer whether the destination needs them or whether the local
copy will later be pushed back to the source host.

To request Reprint's host-plugin cleanup, pass `--exclude-host-plugins` before
the pull starts:

```bash
php reprint.phar pull-files "$URL" \
  --private-key-path=./reprint-key.pem \
    --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --exclude-host-plugins
```

Cleanup skips listed host platform paths, including WP Engine and WP Cloud MU
plugins, even when they are leftovers from an earlier host. Portable plugins
stay: this includes Redis Cache, WP Rocket, SiteGround's Speed and Security
Optimizers, and Force Strong Passwords. Generic cache drop-ins are excluded only
when current preflight paths identify WP Cloud or WP Engine.

The pull choice is saved for that remote in the state directory. Later pulls
use the same selection, and `db-apply` deactivates excluded regular plugins.
`--include-host-plugins` selects preservation again. Existing state keeps its
saved choice; only new state defaults to preservation. Changing the choice
during an unfinished pull requires `--abort` first. Neither flag overrides
explicit `--exclude` paths or the generated-file skip rules above.

**Local runtime cleanup is separate.** `apply-runtime` removes the listed local
copies by default, including plugins that were downloaded by a preserving pull.
Portable plugins stay. Pass `apply-runtime --include-host-plugins` to leave the
local copies in place for that invocation. `apply-runtime --exclude-host-plugins`
explicitly requests the default cleanup. Neither flag changes the saved pull
selection, and the two flags cannot be combined.

The high-level `pull` command prepares a local runtime and uses this default
cleanup regardless of its download selection. Product integrations can download
files unchanged, then run `apply-runtime` before starting WordPress. A hosting
platform can instead keep the plugins and perform its own setup without
running `apply-runtime`. Preserved host plugins may prevent WordPress from
booting without the source host's services.

For push-capable integrations, `files-diff` and `files-push` exclude paths
removed by runtime cleanup, so a later theme push does not delete the source
host's plugins. Skipping a later cleanup does not restore files already
removed. Finish an interrupted files-push, or finish or abort an interrupted
files-pull, before applying runtime cleanup. This does not make other local
runtime or database changes suitable for production.

To fetch plugins skipped by a completed pull, start another `pull-files` with
`--include-host-plugins`, or abort the completed `files-pull` and run it again
with that flag. This cannot restore plugin activation removed by an earlier
import. Runtime setup itself does not connect to or edit the database.

File pulls require `--state-dir` to be outside `--fs-root` in both modes.
Downloaded files must not replace the state used to resume a pull. The selected mode is
saved when `files-pull` starts. To switch modes, first run the same command with
`--abort`, then start it again with the other `--mode` value.

## Non-empty local fs-root

By default, `files-pull` refuses to start if `--fs-root` is non-empty. If you need to use a non-empty local fs-root,
the `--on-fs-root-nonempty` flag controls this behavior. It takes the following values:

- `--on-fs-root-nonempty=error` (default): throw an error and abort.
- `--on-fs-root-nonempty=preserve-local`: import into the non-empty directory while preserving all existing local content.

## Selecting files

Use repeatable source prefixes to include or exclude files. WordPress tokens
expand from the saved preflight report:

```bash
php reprint.phar pull-files "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
  --include=:wp-content: --exclude=:wp-uploads:
```

`--include=SOURCE` supplies an included source prefix and `--exclude=SOURCE`
supplies an excluded prefix. Both accept WordPress tokens or absolute source
paths; exclusions win when they overlap. A source prefix can select a directory
or one file. `--remap SOURCE TARGET` changes where a selected source prefix is
placed; use a target inside the filesystem root.

To download only uploads in a later high-level file pull:

```bash
php reprint.phar pull-files "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
  --include=:wp-uploads:
```

Low-level `files-pull` needs a completed operation cleared with `--abort` before
starting another delta. Use `pull-files` if you want the client to handle
repeat runs without that manual reset.

Older callers can still use `--filter=essential-files` to omit uploads and
low-level `--filter=skipped-earlier` to select only uploads. These are presets
for the same path rules, not a separate skipped-file list. New integrations
can use the explicit include and exclude options above.

Symlinks are followed by default. With `--no-follow-symlinks`, a selected
symlink is copied as a link without indexing its target. A selected path reached
through a symlinked parent is rejected; use the default or
`--follow-symlinks` so Reprint can preserve the requested link path and index
the physical target.

Replacing or deleting a local symlink changes the link itself, not its target.
Selected paths must remain inside the filesystem root after resolving existing
parent links. Reprint reports blocked paths rather than writing outside that root.

See [Windows sources](https://developer.wordpress.org/reprint/guides/windows-sources/) for drive-letter and share paths.

## Rewrite asset URLs

Pass `--new-site-url` or repeat `--rewrite-url FROM TO` before the first file
pull when the destination address differs. `pull` applies its mappings to
both CSS downloads and the database; `pull-files` accepts them too.

CSS rewriting covers `url()`, bare `@import` strings, and `image-set()` strings.
It matches HTTP(S) and protocol-relative URL bases after decoding CSS escapes.
Comments, displayed text, relative URLs, unrelated domains, and other file
types remain unchanged. The rewritten value uses CSS escaping and may gain quotes.
Generated stylesheets under uploads are rewritten rather than removed.

Mappings remain bound to the saved remote index. Later downloads reuse them
when the options are omitted. Different mappings require a fresh state
directory and empty filesystem root. A later `db-apply` or `db-rewrite-urls`
does not change files already downloaded.
