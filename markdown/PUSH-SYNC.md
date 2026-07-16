# Push Sync

Push local changes to a remote WordPress site: files and database, driven
entirely from the local machine, resumable at every step, never leaving the
remote partially committed. This document is the agreed design; the delivery plan at
the end maps it to a PR stack.

## Shape of a push

1. The local machine knows what changed locally since its last push to this
   remote (files, deletions, database rows), by comparing against the local
   baselines it stored at the end of that push.
2. It shows a summary of local uploads and deletions. The user confirms that
   local should win for those paths and rows.
3. It transfers everything into a private push directory on the remote — file bytes,
   a deletion manifest, and (phase two) the database diff. The transfer is
   resumable at byte granularity.
4. It drives the commit step with repeated commands until done. The remote
   moves work files into place, executes deletions, and commits the database
   diff, and fixes symlinks — inside a maintenance window that lasts seconds,
   not the length of the transfer.
5. It stores the current local indexes as the new local baselines. The push is
   complete only after this step; a crashed push is re-driven from the top and
   converges.

Both sides act only when the local machine calls: no worker, no polling, no
sessions. The remote is a passive authenticated API.

## Peers and packages

There is one package: Reprint. Every peer ships the same code with three
capabilities:

- **serve** — index and fetch endpoints (today's export surface),
- **commit** — push session, commit engine, database batch,
- **drive** — the CLI that runs syncs.

"Reprint lite" is a serve-only build for peers that only ever get pulled
from. The WordPress plugin ships the full package.

## Transport and authentication

HTTPS is required. `--force-http` opts out explicitly, and its help text says
what it gives up: over plain HTTP an active attacker can read and modify
transferred content; the flag only keeps the shared secret off the wire and
limits replay.

Every request carries an HMAC signature over exactly four values — the
HTTP method, the URL's path and query, a timestamp, and a random nonce. Payloads are not signed and not hashed: TLS already
guarantees their integrity, and signing streams was the single biggest source
of buffering pain. Signing cost is constant per request regardless of payload
size. The secret travels in no URL and no body, so it never lands in an
access log.

Authentication does not grant write authority. Connection tokens are
download-only by default, including tokens that predate push endpoints. After
authentication, every `push_*` operation passes one authorization gate before
the endpoint reads upload data, creates a push directory, or changes the
document root. Missing authorization returns HTTP 403 with
`reason: "push_disabled"`; custom authentication uses the same gate.

Personal consent stores only the current connection token's SHA-256
fingerprint. Rotating the token therefore revokes push access. A hosting
provider may override local consent by defining the boolean
`SITE_EXPORT_PUSH_ENABLED` constant before active plugins load or by setting
the environment variable of the same name. Managed `true` enables push and
managed `false` hard-disables it.

## Change detection: local machine compared against itself

ctime is machine-local, so push never compares a local timestamp to a remote
one. It only answers "what changed locally since my last successful push to
this remote" by comparing the current local indexes against local baselines.

The local machine keeps one set of baselines **per remote site**, overwritten
after each successful commit:

    <state-dir>/push/<site>/last-sync-local-files.jsonl
    <state-dir>/push/<site>/last-sync-local-rows.jsonl   (phase two)

Each file baseline is a copy of a local file index in the `.import-index.jsonl`
format the pull path already reads and writes — one JSON object per line,
sorted by path.

The first push to a site has no baselines: every current local file counts as
changed, and no local deletion can be detected yet.

Push is intentionally local-wins. If a developer edited the remote site
outside Reprint, a later push of the same path or row overwrites that remote
edit. This keeps push as a simple deployment tool instead of a conflict
manager.

## Deletes

Local deletions since the last push come out of the baseline comparison. They
travel as NUL-delimited document-root-relative paths in `work/deletes`. Commit
records its byte offset before and after every destructive mutation, so a
later request can resume from durable evidence instead of repeating a delete.

## The push session

`Site_Export_Push_Session` stores each session at
`<reprint-directory>/.reprint/push/<push-session-id>/`. `push.json` is the
push identity and policy plus whether the work-delete stream is complete.
`commit.json` holds the bounded commit cursor. Both are atomically replaced
and validated against the current schema.

`work/files/` contains completed work values only. A single `work/inflight.json`
record identifies the value currently being received or published, and
`work/inflight.data` contains in-flight file bytes. Its actual size is the
receiver-confirmed cursor. A file replayed from byte zero restarts the same
path; a continuation must begin at that confirmed cursor. Another positive-work
path is rejected until the in-flight work is complete. `work/deletes` is the
corresponding durable delete queue.

Publication records its phase before it creates work-file parents or replaces
the completed value. A status, upload, or commit request can therefore finish
a publication after a lost response. Commit refuses to begin while work is
still being prepared or received, so it traverses completed work files only.

`remove()` renames a removable push directory to
`.removing-<push-session-id>/` before bounded cleanup. A false return means
more cleanup remains and must be retried. A push with a commit in progress is
not removable; its next commit request resumes the durable cursor instead.

## Push HTTP operations

The production exporter router exposes five authenticated operations. Control
and upload requests use the envelope signature described above, so
`push_upload` passes `php://input` directly to the multipart processor instead
of reading the complete request for authentication.

- `POST push_create` creates or reopens the caller's 32-character lowercase
  hexadecimal `push_session_id`. A successful response contains `status`,
  `push_session_id`, `max_part_bytes`, `post_max_bytes`, and
  `excluded_paths_b64`. The two limits describe different dimensions: one
  multipart part and the complete decoded request body. The endpoint enforces
  the request-body limit while reading bounded fragments, including chunked
  requests without `Content-Length`. `excluded_paths_b64` is the normalized,
  sorted, immutable server policy stored for the push session; base64 preserves
  arbitrary path bytes which JSON cannot represent directly. A sender
  consuming this field must omit indexed paths equal to, below, or ancestors
  of any advertised exclusion.
- `POST push_upload` accepts `multipart/mixed`. A successful response contains
  `status`, `push_session_id`, `changes_accepted`, and `last_change`. The last
  change is null for an empty request. Otherwise it contains `state`, `type`,
  and `accepted_bytes`, plus `path_b64` for a file, directory, or symlink. A
  delete-list change has no path. Only the latest change is retained for the
  response; request memory does not grow with the number of parts.
- `GET push_status` accepts an optional base64 `path_b64`. A successful
  response contains `status`, `push_session_id`, `phase`,
  `work_deletes_bytes`, `work_deletes_complete`, and `path`. The path is null
  when none was requested. Otherwise it contains `path_b64`, `state`, and
  `accepted_bytes`; a non-missing path also contains `type`.
- `POST push_commit` performs one server-bounded commit call. A successful
  response contains `status`, `push_session_id`, `phase`,
  `send_next_request`, and `entries_processed`. The sender repeats it while
  `send_next_request` is true.
- `POST push_remove` performs one bounded remove step. A successful response
  contains `status`, `push_session_id`, and `removed`. The sender repeats it
  while `removed` is false.

Push deployments must set `display_errors=Off` at PHP startup through
`php.ini`, `.user.ini`, or the PHP-FPM pool configuration; changing it after
the request starts is too late for PHP's own `post_max_size` warning.
`log_errors=On` is recommended. When the endpoint code receives an oversized
request it returns a classified push JSON failure, but PHP or the web server
may reject a request before the exporter runs and that response need not use
the push JSON protocol. The sender therefore also treats a bare HTTP 413 as
`request_too_large` and learns a smaller request ceiling.

The WordPress plugin passes the platform-supplied `docroot` to push endpoints,
defaulting to the web server's `DOCUMENT_ROOT`. A platform supplies the complete
trusted API-options array through the early `site_export_api_options` filter; a
direct embedder passes the same array to `_site_export_handle_api_request()`.
The document-root path must resolve to an existing directory. `ABSPATH` remains
the default only for pull endpoints because it may point at a separate shared
WordPress core tree. Push work lives in a document-root-specific private
directory beside the canonical document root unless server configuration
supplies `reprint_directory`.
Configured reprint directories must remain outside the document root; the HTTP
endpoints reject an inside path because they do not yet apply the indexing and
web-access protections described below. The plugin always excludes its logical
installed directory from push, including when that directory is a symlink to a
physical target outside the document root. A platform hook or embedding router
must choose its document root, reprint directory, and excluded paths as server
configuration; request parameters cannot select any of them.

## Where reprint stores its own data on the remote

The remote is configured with one storage path for everything reprint keeps:
the private push directory and any commit bookkeeping. The current push HTTP
endpoints require this path to be outside the document root. They do not yet
exclude an inside path from every file index or write web-server access guards,
so endpoint construction rejects that configuration instead of exposing private
push work. A host which cannot write outside the document root is not supported
until those protections exist.

## Commit

Remote-side, journaled, idempotent, driven by repeated commands until done.
Order:

1. **Receive work, outside maintenance:** multipart requests write one bounded
   chunk at a time into `work/inflight.data` and publish complete values in
   `work/files`. The site runs normally throughout receipt.
2. **Maintenance on.** Commit writes the `.maintenance` file itself, and since
   WordPress executes that file, ours whitelists reprint API requests
   (`$upgrading = 0` for us, `time()` for everyone else). WordPress's own
   rule that a `.maintenance` file older than 10 minutes is ignored stays
   intact, so an interrupted commit can never leave the site down for good.
3. **Commit:** consume `work/deletes`, then `work/files`, with the durable
   `commit.json` checkpoint written before each document-root mutation. The
   future database batch and symlink updates follow the same bounded cursor.
4. **Maintenance off:** commit releases its `commit-state` ownership after
   completion; the driver updates local baselines after commit
   completes.

If the driver dies mid-commit: WordPress stops honoring the `.maintenance`
file after 10 minutes on its own, and the next commit request resumes from
`commit.json` — no worker or cron needed.

**The reprint plugin's own directory is never touched by commit.** It is
excluded from installs and deletes and reported as excluded in the summary.
Updating reprint itself is a separate concern, never part of a sync.

## Escape hatch: commit without booting WordPress

Normally the endpoint runs through the WordPress boot because it is
convenient. But a commit step can break that boot — a fatal in a partially
committed plugin — so the same endpoint is also reachable as a standalone PHP file that
never loads WordPress: it reads database credentials from `wp-config.php`
directly and operates on the filesystem and database with reprint's own code.
The driver falls back to it automatically when the normal route stops
answering sensibly. This is what makes commit failures recoverable from the
outside instead of requiring SSH.

## Database diff (phase two)

The database is pushed as a diff — INSERT, UPDATE, DELETE — never as a dump
that replaces tables. The mechanics mirror the file design:

- A **row index** — `(table, primary key, row hash)` — plays the role
  `(path, ctime, size)` plays for files. The local machine keeps the row
  index from the last push as a baseline; diffing against it yields the
  upsert and delete sets.
- Push is local-wins for rows too. Rows changed on the remote outside Reprint
  are overwritten when the local diff touches the same primary key.
- The diff stream passes through the URL rewriter in the local-to-remote
  direction before work.
- Volatile rows are excluded by default (transients, sessions, cron), the
  same way volatile files are handled in pull.
- The batch executes inside the commit maintenance window, bounded and
  resumable like every other step.

## Accepted limitations

Stated here so nobody rediscovers them as surprises:

- **Same-size corruption is invisible.** Transfers are verified by byte
  count only. Corruption that preserves length passes. We decided detection
  is not worth hashing multi-gigabyte work files inside PHP time limits.
- **Remote edits are overwritten.** Push is a local-wins deployment tool, not
  a bidirectional conflict resolver. Developers who edit the remote site
  outside Reprint are responsible for pulling or preserving those changes
  before pushing over them.
- **Shell and cron can write during the maintenance window.** Maintenance
  blocks web requests; it cannot block SSH or system cron.
## Delivery plan

Files first, database second, each PR small and stacked in this order:

1. **Design doc** — this file.
2. **Envelope auth** — headers-only HMAC for data routes: the
   X-Auth-Content-Hash header carries the literal string UNSIGNED-PAYLOAD,
   and the signature covers the method and request target instead of a
   body hash.
3. **Work value store** — the store itself (PR #317, which succeeded
   the closed #298).
4. **Reprint-storage exclusions** — indexer and deletion-sync hard-exclude
   the configured storage path; web guards for inside-docroot placement.
5. **Push journal and local diff** — per-site local baselines, capture and
   overwrite logic, local change and deletion detection.
6. **Push stream endpoint** — the store's HTTP surface plus a sender that
   streams framed chunks for many files through one authenticated request;
   deletion work received; `--force-http` with honest help text (the first
   push networking this flag can gate). Decisions this slice locked in:
   sending streams through libcurl's pause mechanism, which PHP's curl
   extension supports from 8.1 — so `reprint push` requires PHP 8.1+ (pull
   keeps 7.4+; the full story is
   https://github.com/WordPress/reprint/issues/327) — and paths
   travel base64-encoded in frames, response cursors, and control-plane
   parameters, because file paths are arbitrary bytes and JSON strings must
   be UTF-8.
7. **Package unification** — importer and exporter become one Reprint
   package (lite = serve-only build). Placed here because commit is the
   first piece that needs import-side code running on the remote.
8. **Commit engine, files** — delete `work/deletes`, then rename values from
   `work/files` into the document root with the whitelisted maintenance file
   and resumable `commit.json` cursor.
9. **Standalone escape hatch** — the no-boot endpoint and driver fallback.
10. **Row index and database diff** — the local row index, row baseline,
    diff generation and URL rewrite, the commit batch.
11. **`reprint push`** — the one command that orchestrates plan, confirm,
    transfer, commit, resume.
12. **Budgets and resumable limits** — push requests stay bounded by two
    budgets of different dimensions: the fixed chunk (the sender's in-memory
    unit of one read) and the host-learned request body budget that
    PushRequestSizer sizes from reported php.ini limits and 413s, plus a
    wall-clock budget per request; any endpoint that stops after durable work
    returns the exact committed state the driver needs to retry. The commit step
    gets the main budgeted loop: process until a deadline or operation limit,
    return progress, and let the driver re-enter until complete.
