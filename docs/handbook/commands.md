# Command reference

Use the high-level commands for a first copy or product integration. Use the
smaller commands when your product needs to control individual stages.

```sh
php reprint.phar --help
php reprint.phar pull --help
```

Command-specific help describes the complete options and defaults for the
client you downloaded. Most transfer commands take a source URL, `--state-dir`,
and `--fs-root`; local-only commands have different requirements.

## Complete workflows

| Command | Result |
| --- | --- |
| `pull` | Preflight, files, database download and apply, optional flattening, and runtime setup. |
| `pull-files` | Preflight and file download, without database apply or runtime setup. |
| `pull-db` | Preflight, database download, and database apply, without file or runtime changes. |
| `install-server` | Installation instructions for the source-site plugin; it does not install the plugin remotely. |

Start with [Copy a site](https://developer.wordpress.org/reprint/copy-a-site/) or [Build with Reprint](https://developer.wordpress.org/reprint/build-with-reprint/).

## Access and inspection

| Command | Result |
| --- | --- |
| `keygen` | A private key and a public Migration Token to enroll on one source site. |
| `preflight` | A report of the source environment, also saved for subsequent stages. |
| `preflight-assert` | Checks of the saved preflight report without another request. |
| `pull-metadata` | Saved pull lifecycle, artifacts, source details, and stored-key metadata; no network request. |
| `files-stats` | File counts and sizes from the next remote index. |
| `db-index` | Database table metadata written to `db-tables.jsonl`. |

See [Authentication](https://developer.wordpress.org/reprint/authentication/), [Preflight](https://developer.wordpress.org/reprint/preflight/), and
[Progress, results, and exit codes](https://developer.wordpress.org/reprint/progress-and-results/).

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

See [File transfers](https://developer.wordpress.org/reprint/file-transfers/), [Database transfers](https://developer.wordpress.org/reprint/database-transfers/),
[Runtime configuration](https://developer.wordpress.org/reprint/runtime/), and [Prepare a hosting destination](https://developer.wordpress.org/reprint/hosting/).

## Local change inspection and push

The client can inspect local changes without uploading. Uploads need a
separately configured push-capable target. The WordPress.org server package
does not include push implementations; configuring it cannot enable them.

| Command | Result |
| --- | --- |
| `files-diff` | Local changes compared with the retained local index; no upload. |
| `files-push` | A resumable file push to a separately configured push-capable target. |
| `db-push` | A staged full database overwrite requiring explicit confirmation. |

Hosts developing that integration should use the implementation and configuration
notes in the [development repository](https://github.com/WordPress/reprint).
Do not offer push using the WordPress.org server package.

## Shared behavior

Keep the same source address, state directory, and filesystem root to continue
a transfer. Use one state directory per filesystem root, outside the downloaded
site. Do not run commands concurrently against the same state directory.

`--abort` is command-specific: it can discard an unfinished output or checkpoint.
Read the command's help before using it. It is not a general rollback command.
Run the same command to continue an interrupted transfer. Use `pull-files`
to fetch later file changes without replacing the local database. To reopen
the local site without copying again, use the generated start script from
[Copy a site to your computer](https://developer.wordpress.org/reprint/copy-a-site/).
