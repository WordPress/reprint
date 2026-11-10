# Command reference

Use the high-level commands for a first copy or product integration. Use the smaller commands when your product needs to control individual stages.

```sh
php reprint.phar --help
php reprint.phar pull --help
```

Command-specific help describes the complete options and defaults for the client you downloaded. This page names every command, the conventions the handbook examples share, and the options those examples use.

## Conventions

Reference pages write commands with three shell variables. Set them before pasting an example:

| Variable | Meaning |
| --- | --- |
| `$URL` | The source's Reprint API address, shown in **Tools → Reprint Server**. |
| `$STATE_DIR` | A private directory for the client's credentials, saved progress, SQL download, and generated server files. |
| `$FS_ROOT` | The directory that downloaded site files are written to. |

**Site address or API address.** `pull`, `pull-files`, and `pull-db` accept the site address, such as https://example.com. Other commands need the Reprint API address, such as https://example.com/?reprint-api, shown in **Tools → Reprint Server**.

**Credentials.** Commands that contact the source need one credential flag: `--private-key-path=./reprint-key.pem` after creating and authorizing that key with `keygen`, or `--secret='TOKEN'` when the source uses a connection token. Never pass both. Local-only commands such as `db-apply`, `apply-runtime`, and `preflight-assert` take no credential. See [Authentication](https://developer.wordpress.org/reprint/reference/authentication/).

**Directories.** Keep `$STATE_DIR` outside `$FS_ROOT` and outside every web-served directory. Use one state directory per filesystem root, and do not run two commands against the same state directory at once. To continue an interrupted transfer, run the same command with the same source address, state directory, and filesystem root.

## Complete workflows

| Command | Result |
| --- | --- |
| `pull` | Preflight, files, database download and apply, optional flattening, and runtime setup. |
| `pull-files` | Preflight and file download, without database apply or runtime setup. |
| `pull-db` | Preflight, database download, and database apply, without file or runtime changes. |
| `install-server` | Installation instructions for the source-site plugin; it does not install the plugin remotely. |

Start with [Clone your site](https://developer.wordpress.org/reprint/getting-started/copy-a-site/) or [Build with Reprint](https://developer.wordpress.org/reprint/build-with-reprint/).

## Access and inspection

| Command | Result |
| --- | --- |
| `keygen` | A private key and a public Migration Token to enroll on one source site. |
| `preflight` | A report of the source environment, also saved for subsequent stages. |
| `preflight-assert` | Checks of the saved preflight report without another request. |
| `pull-metadata` | Saved pull lifecycle, artifacts, source details, and stored-key metadata; no network request. |
| `files-stats` | File counts and sizes from the next remote index. |
| `db-index` | Database table metadata written to `db-tables.jsonl`. |

See [Authentication](https://developer.wordpress.org/reprint/reference/authentication/), [Preflight](https://developer.wordpress.org/reprint/reference/preflight/), and [Progress, results, and exit codes](https://developer.wordpress.org/reprint/reference/progress-and-results/).

## Data transfer and preparation

| Command | Result |
| --- | --- |
| `files-index` | A remote file index, without downloading file contents. |
| `files-pull` | A file pull in catch-up or mirror mode, using a saved preflight report. |
| `db-pull` | A SQL dump, or SQL streamed to stdout or a MySQL connection. |
| `db-apply` | A database import with optional URL rewriting. |
| `db-rewrite-urls` | URL changes directly in an existing database. |
| `flat-docroot` | A standard WordPress layout assembled with symlinks to downloaded files. |
| `merge-wp-content` | Local-only wp-content entries moved into the pulled tree. |
| `apply-runtime` | Generated target runtime configuration and local source-host cleanup. |
| `post-process` | Selected hosting cleanup and plugin startup-recovery tasks. |
| `recover` | A WordPress startup check that can deactivate a failing regular plugin. |

See [File transfers](https://developer.wordpress.org/reprint/reference/file-transfers/), [Database transfers](https://developer.wordpress.org/reprint/reference/database-transfers/), [Runtime configuration](https://developer.wordpress.org/reprint/reference/runtime/), and [Prepare a hosting destination](https://developer.wordpress.org/reprint/build-with-reprint/hosting/).

## Local change inspection and push

Push sends local file or database changes back to a site. The client can inspect local changes without uploading. Uploads need a separately configured push-capable target. The WordPress.org server package does not include push implementations; configuring it cannot enable them.

| Command | Result |
| --- | --- |
| `files-diff` | Local changes compared with the retained local index; no upload. |
| `files-push` | A resumable file push to a separately configured push-capable target. |
| `db-push` | A staged full database overwrite requiring explicit confirmation. |

Hosts developing that integration should use the implementation and configuration notes in the [development repository](https://github.com/WordPress/reprint). Do not offer push using the WordPress.org server package.

## Options used in this handbook

Each command's `--help` lists its complete options. These are the ones the handbook examples use, with the commands that accept them.

| Option | What it does | Commands |
| --- | --- | --- |
| `--state-dir=DIR` | Private directory for credentials, saved progress, the SQL download, and generated server files. | Every command that reads or writes state. |
| `--fs-root=DIR` | Directory that site files are written to or read from. | Transfer commands, `apply-runtime`, `recover`, `post-process`. |
| `--private-key-path=PATH` | Private key that signs requests; overrides a key stored in the state directory. | Commands that contact the source. |
| `--secret=TOKEN` | Connection token for a source whose host lacks OpenSSL. | Commands that contact the source. |
| `--out=PATH` | Write the generated private key to PATH instead of the state directory. | `keygen` |
| `--insecure` | Allow an HTTP address and skip HTTPS certificate checks. `REPRINT_INSECURE_TLS=1` does the same. | Every command. |
| `--progress=MODE` | Progress output: `auto`, `tty`, `jsonl`, or `compact`. JSONL and compact append a final command report. | Every command. |
| `--step=N`, `--steps=N` | Pipeline position written to `progress.json`. | Every command. |
| `--verbose` | Print detailed request and response logs, including audit-log entries. | Transfer and runtime commands. |
| `--abort` | Discard the named command's unfinished work; its help says what is kept. | `pull`, `pull-files`, `pull-db`, `files-pull`, `files-index`, `db-pull`, `db-index`, `db-apply`, `db-rewrite-urls`, and the push commands. |
| `--target-engine=ENGINE` | Destination database engine: `mysql` or `sqlite`. | `pull`, `pull-db`, `db-apply`, `db-rewrite-urls`, `apply-runtime` |
| `--target-host`, `--target-port`, `--target-user`, `--target-pass`, `--target-db` | Destination MySQL connection. The user and database name are required for MySQL. | Same as `--target-engine`. |
| `--target-sqlite-path=PATH` | Destination SQLite file. Default: `wp-content/database/.ht.sqlite` under the copy. | Same as `--target-engine`. |
| `--new-site-url=URL` | New site address. Creates the matching `--rewrite-url` from the source address. | `pull`, `pull-files`, `files-pull`, `pull-db`, `db-apply`, `db-rewrite-urls` |
| `--rewrite-url FROM TO` | Replace FROM with TO in the database and CSS. Repeatable. | Same as `--new-site-url`, plus `db-push`. |
| `--include=SOURCE`, `--exclude=SOURCE` | Restrict the file pull to, or omit, a source prefix: a token such as `:wp-content:` or an absolute source path. `:wp-core:` is the WordPress core the site runs; `--exclude=:wp-core:` pulls everything else. Exclusions win. | `pull-files`, `files-pull` |
| `--remap SOURCE TARGET` | Place a selected source prefix at TARGET inside the filesystem root. | `pull-files`, `files-pull` |
| `--mode=MODE` | File pull mode: `catch-up` (default) or `mirror`. | `pull`, `pull-files`, `files-pull` |
| `--on-fs-root-nonempty=ACTION` | `error` (default) or `preserve-local` when the filesystem root already has files. | `pull`, `pull-files`, `files-pull` |
| `--no-follow-symlinks` | Copy a selected symlink as a link without indexing its target. | `pull`, `pull-files`, `files-pull` |
| `--exclude-host-plugins`, `--include-host-plugins` | Skip or keep host platform plugins; the choice is saved in state. On `apply-runtime`, they control local cleanup only. | `pull`, `pull-files`, `pull-db`, `files-pull`, `db-apply`, `apply-runtime` |
| `--sql-output=MODE` with `--mysql-host`, `--mysql-port`, `--mysql-user`, `--mysql-password`, `--mysql-database` | Where `db-pull` writes SQL: `file` (default), `stdout`, or `mysql`. | `db-pull` |
| `--site-admin=LOGIN` | Imported user who administers a site copied out of a network. | `pull`, `pull-db`, `db-apply` |
| `--flatten-to=PATH` | Assemble a standard WordPress layout at PATH with symlinks to the downloaded tree. `--core-files=copy` copies WordPress core instead. | `pull`, `flat-docroot` |
| `--core-files=MODE` | Place WordPress core in that layout as `link` (default) or `copy`. | `pull`, `flat-docroot` |
| `--runtime=RUNTIME` | Server configuration to generate: `php-builtin`, `nginx-fpm`, `playground-cli`, or `none`. | `pull`, `apply-runtime` |
| `--start-runtime=RUNTIME` | Runtime to launch after `pull` finishes; `none` starts nothing. | `pull` |
| `--output-dir=DIR` | Directory for generated runtime files. | `pull`, `apply-runtime` |
| `--flat-document-root=DIR` | Use an assembled layout as the document root. | `apply-runtime` |
| `--tasks=LIST` | Post-processing tasks to run, or `all`. | `post-process` |

See [Runtime configuration](https://developer.wordpress.org/reprint/reference/runtime/#runtime-options-on-pull) for how the runtime options combine on `pull`.

## Shared behavior

`--abort` is command-specific: it can discard an unfinished output or checkpoint. Read the command's help before using it. It is not a general rollback command. Run the same command to continue an interrupted transfer. Use `pull-files` to fetch later file changes without replacing the local database. To reopen the local site without copying again, use the generated start script from [Clone your site](https://developer.wordpress.org/reprint/getting-started/copy-a-site/).
