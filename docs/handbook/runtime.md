# Runtime configuration reference

Examples use `$URL`, `$STATE_DIR`, and `$FS_ROOT` as described in [Conventions](https://developer.wordpress.org/reprint/reference/commands/#conventions). These commands use saved source information and local files; no source credential flag is needed.

Use `$FLAT_DIR` for the assembled WordPress directory and `$RUNTIME_DIR` for generated configuration. Create the assembled layout first when using `--flat-document-root`:

```bash
php reprint.phar flat-docroot "$URL" --state-dir="$STATE_DIR" \
  --fs-root="$FS_ROOT" --flatten-to="$FLAT_DIR"
```

By default this creates symlinks to the downloaded tree; it does not copy the files. Keep the download directory while using that layout.

With `--core-files=copy`, WordPress core is copied instead of linked. Every run brings the copy back in line with the downloaded core, so local edits to core are discarded and never reach `--fs-root`. `wp-includes/version.php` is copied last, so a copy that stops partway still reports the previous WordPress version, and a caller comparing versions copies again. `wp-content`, `wp-config.php` and similar config files, and other site files stay linked. `--flatten-to` must be outside `--fs-root`, and a symlink inside core stops the run. To go back to links, run `flat-docroot` with `--force`.

With `--core-files=use-existing`, the WordPress core already at `--flatten-to` stays as it is, and everything else is linked as usual. The download tree does not need core, so a pull can leave it out, for example with `--include=:wp-content:` when the target already runs the source's WordPress version. Reprint does not compare that core with the source's: the caller decides when using it is correct. The run stops if `--flatten-to` has no `wp-includes/version.php`, or if its `wp-includes` is a link left by an earlier link-mode run; run `--core-files=copy` once to replace those links. A real `wp-content` directory or other site file at `--flatten-to` stops the run as in the other modes, and `--force` replaces it with a link, deleting what it contained. `pull --core-files=use-existing` checks the core before it downloads anything.

The downloaded files need server-specific configuration to actually work — PHP constants, INI directives, and request handlers that the source host relied on. `apply-runtime` reads the preflight data, detects the source hosting provider, and generates the configuration files your target server needs.

For PHP's built-in development server:

```bash
php reprint.phar apply-runtime "$URL" --state-dir="$STATE_DIR" \
    --flat-document-root="$FLAT_DIR" --output-dir="$RUNTIME_DIR" --runtime=php-builtin
bash "$RUNTIME_DIR/start.sh"
```

For nginx + PHP-FPM:

```bash
php reprint.phar apply-runtime "$URL" --state-dir="$STATE_DIR" \
    --flat-document-root="$FLAT_DIR" --output-dir="$RUNTIME_DIR" --runtime=nginx-fpm
# Include $RUNTIME_DIR/nginx.conf in your nginx configuration, then reload
```

The nginx runtime keeps document roots and host names as literal configuration values, including spaces and punctuation. Dollar signs and control characters in the document root, output path, or host are rejected before files are written. Its generated listener binds to `127.0.0.1`, and requests for dotfiles or paths inside dot-directories return 404. To accept remote clients, change the `listen` address in the generated `nginx.conf`. The configured SQLite database and its companion files also return 404, even when their names do not start with a dot.

The command accepts either `--fs-root` (the raw download directory — the remote `document_root` path is appended automatically) or `--flat-document-root` (a directory created by `flat-docroot`, used as-is). These are mutually exclusive.

Host and port default to the URL rewrite target from `db-apply`. Override with `--host` and `--port`. For nginx, the host sets `server_name`; the listener remains on `127.0.0.1` unless you edit the generated configuration.

The database written into `runtime.php` is the one `db-apply` connected to. To point the runtime at a database `db-apply` did not create — a local database you are keeping, for instance, when you pull the files but not the database — name it with the same `--target-*` options `db-apply` takes:

```bash
php reprint.phar apply-runtime "$URL" --state-dir="$STATE_DIR" \
    --flat-document-root="$FLAT_DIR" --output-dir="$RUNTIME_DIR" --runtime=php-builtin \
    --target-engine=sqlite --target-sqlite-path="$FLAT_DIR/wp-content/database/.ht.sqlite"
```

The SQLite file itself may be missing — the `sqlite-database-integration` plugin creates it on the first request — but its directory must exist. The options apply to this run only; `apply-runtime` does not record them in state.

Every field you leave out falls back to what `db-apply` recorded, as long as the engine matches. Name the whole connection when you point at a different MySQL database — otherwise you inherit `db-apply`'s host, port or password.

**What gets generated:**

The command produces a `runtime.php` file that sets PHP constants, server variables, and route handlers the source site needs. Each target runtime wraps it differently:

| Runtime | Output files | How runtime.php loads |
|---------|-------------|----------------------|
| `php-builtin` | `runtime.php`, `start.sh` | Used as the router script for `php -S` |
| `nginx-fpm` | `runtime.php`, `nginx.conf` | Loaded via `auto_prepend_file` in `fastcgi_param PHP_VALUE` |

Reprint has source-host support for WP Cloud and WP Engine, plus a generic default for other hosts. WP Cloud support can generate missing image sizes in the copy. `apply-runtime` removes known host-plugin copies by default, independently of the saved download selection. Pass `apply-runtime --include-host-plugins` to skip that local cleanup. See [host platform plugins](https://developer.wordpress.org/reprint/reference/file-transfers/) for download selection and cleanup. Currently supported target runtimes: nginx + PHP-FPM, PHP's built-in development server, and WordPress Playground CLI.

Playground previews serve downloaded files only. They do not proxy missing uploads from the source or add a pull-state mount. Keep `--state-dir` outside the site directories. Run `apply-runtime` again to refresh an existing runtime's generated configuration.

<h2 id="runtime-options-on-pull">Runtime options on pull</h2>

The high-level `pull` command runs these stages itself. Four options control them:

- `--flatten-to=PATH` assembles the standard layout at PATH, the same as running `flat-docroot`. Keep the download tree; the layout links to it. Add `--core-files=copy` to copy WordPress core instead of linking it, or `--core-files=use-existing` to use the core already at PATH.
- `--runtime=RUNTIME` selects which server configuration `pull` generates after the database import: `php-builtin` by default, `nginx-fpm`, `playground-cli`, or `none` to generate nothing.
- `--start-runtime=RUNTIME` selects which runtime `pull` launches when it finishes. Without it, `pull` starts a generated `php-builtin` or `playground-cli` runtime and leaves `nginx-fpm` for you to load. Pass `none` to prepare the copy without starting a server, for example on a destination with its own web server or inside a product's background job. Naming a runtime here also generates its configuration.
- `--output-dir=DIR` chooses where the generated files go. The tutorials use the default, the `runtime` directory inside the state directory, which is why they start a finished copy with `bash state/runtime/start.sh`.
