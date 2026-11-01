# Architecture and durable state

Read the [public explanation](../handbook/how-it-works.md) for the user model.
This document is for changes spanning the client, server, state, or runtime.
Small local rules belong beside their implementation and in regression tests.
Use the [terminology contract](push-terminology.md) for exact path and lifecycle names.

## Client and server

[ImportClient](../../packages/reprint-client/src/import.php) drives pull workflows.
The source [HTTP server](../../packages/reprint-server/src/class-http-server.php)
authenticates and dispatches requests; it does not schedule a background migration.
The [pull protocol](pull-http.md) describes request fields and response framing.

The client offers both a high-level pull pipeline and smaller commands. The
pipeline coordinates saved preflight, file transfer, database download and apply,
flattening, and runtime setup. Keep that coordination in the pipeline rather
than requiring integrations to duplicate it. The public
[integration guide](../handbook/build-with-reprint.md) recommends that path first.

## State is not a second public API

The caller supplies one state directory per filesystem root. Commands addressing
one remote Reprint API URL share its remote state directory. Changing the
filesystem root while retaining that state is rejected. Local command workflows
use the state-directory-wide Reprint process lock.

```text
<state-dir>/
  process.lock
  progress.json
  audit.log
  remotes/<md5-of-trimmed-remote-reprint-api-url>/
    key.pem
    local_index.jsonl
    pull/
      state.json
      remote-index.jsonl
      remote-index.next.jsonl
      index.wal
      fetch-list.jsonl
      volatile-files.json
    push/
```

Use the [state classes](../../packages/reprint-client/src/lib/state/) and
[terminology contract](push-terminology.md) when changing these records. They
contain cursors, tuning state, and per-command checkpoints. Do not reproduce
all private fields as an integration schema. `pull-metadata`, command reports,
and the documented `progress.json` shape are the public inspection surfaces.

The pull index WAL records completed local actions awaiting projection into
remote and local indexes. Resume must retain or replay the unconfirmed suffix;
bytes beyond a durable offset are not completed work. The next remote index,
fetch list, and file cursors are streamed instead of loaded as complete arrays.
Files-diff and files-push must not consume an unfinished files-pull WAL.

A changed source file is tracked for a later file pass. Successful later
fetches clear that file's volatile record. File-fetch errors use the same path
mapping and selection as file data; they must not remove arbitrary local paths.
Existing physical parents are checked before local removal or replacement.

For file push, [PushFilesSender and PushPlan](push.md) share one caller-visible
lifecycle. Read their interruption and target-confirmation rules before changing
checkpoint timing. Database push has its own
[full-overwrite lifecycle](database-push.md).

## Source paths and filesystem writes

Source path format comes from preflight, not a guessed path prefix. The same
text can mean a Unix path or a Windows share. Relative selections and resolved
symlink targets need different handling; keep those concepts separate.
[RemoteToLocalPathMapper](../../packages/reprint-client/src/lib/pull/class-remote-to-local-path-mapper.php)
applies the saved mapping before writes.

Windows reads use PHP file functions, not FFI or an external program. Long UNC
paths use a PHP-supported namespace spelling at I/O time without changing the
index's path coordinates. Keep the user-visible path restrictions and destination
limits in [Windows sources](../handbook/windows-sources.md). The Windows migration
workflow covers drive and share sources against a Linux destination.

## Source hosts and target runtimes

[Host analyzers](../../packages/reprint-client/src/lib/host/) read preflight data
and produce a declarative RuntimeManifest: constants, INI settings, and routes.
[Runtime appliers](../../packages/reprint-client/src/lib/target-runtime/) write
configuration for PHP's built-in server, nginx/PHP-FPM, or Playground CLI.
This keeps source-host detection separate from destination server generation.

Download selection and local runtime cleanup are separate choices. A preserving
pull can retain source-host files, then runtime setup can remove their local
copies. Cleanup records those document-root-relative paths for file-push
exclusions before removing files. It must not teach a later push to delete the
source host's own plugins. See [File transfer reference](../handbook/file-transfers.md)
for the public options.

For Playground CI, `PHP_BINARY=tests/e2e/ci/playground-php.sh` selects the wrapper.
`WP_MYSQL_PARSER_EXTENSION_MANIFEST` selects the local PHP.wasm runner so it can
load the SQLite integration's native parser extension. The
[verification script](../../tests/e2e/ci/verify-wp-mysql-parser.php) checks the
active lexer and parser, not just whether an extension loaded.

## Streaming CSS rewriting

The caller feeds `append_bytes()`, scans with `next_url()`, edits with
`set_raw_url()`, and marks the real file end with `input_finished()`. Completed
output is flushed after each URL. The shared parser cursor is an opaque string
with no source bytes. An HTTP part can end inside a URL, so Reprint saves its
unfinished bytes separately beside that cursor. Resume supplies those bytes
before appending the next HTTP part. A URL split across requests is rewritten once.

The processor keeps each unfinished CSS token and parses it again when more
bytes arrive. A large comment or embedded image therefore increases memory
use and saved state size; token size is not capped. Completed input is released.
Malformed string and URL tokens stay unchanged. More than 128 nested
`image-set()` functions are rejected. An error names the stylesheet; the
partial file is not marked complete.
