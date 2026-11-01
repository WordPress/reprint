# Control each migration step

Start with [Build with Reprint](../build-with-reprint/). Its high-level `pull`
example is enough when your product does not need separate stages. This guide
is for products that must choose when to index, copy, import, and start WordPress.

The examples use shell variables: `$URL` is the source URL, `$STATE_DIR` is
persistent private state, and `$FS_ROOT` is the downloaded tree. Set them in
your shell. Keep state outside every web-served directory and outside the
filesystem root. The same source address selects the same saved remote state.

## 1. Authorize and inspect the source

Generate and enroll a Migration Token before starting low-level requests:

```bash
php reprint.phar keygen "$URL" --state-dir="$STATE_DIR" --progress=jsonl
php reprint.phar preflight "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --progress=jsonl
php reprint.phar preflight-assert "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --progress=jsonl
```

Pause your job between key generation and preflight while the administrator
authorizes the client. A host without OpenSSL uses `--secret=TOKEN` instead.
See [Authentication](../authentication/) and [Preflight](../preflight/).

## 2. Download files

```bash
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --progress=jsonl
```

Choose filters and path selections before the run. The download can keep the
source host's original layout; a later step can assemble a conventional root.
See [File transfer reference](../file-transfers/).

## 3. Download and apply the database

```bash
php reprint.phar db-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --progress=jsonl
php reprint.phar db-apply "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
  --target-engine=sqlite --new-site-url=http://localhost:8881 --progress=jsonl
```

Use destination-specific MySQL options instead when needed. Database apply can
replace existing data. Choose the target database and URL replacements before
starting; keep those choices while resuming. Supply file URL mappings to
`files-pull` too when CSS assets need rewriting. Later database rewriting does
not change already downloaded files.
See [Database transfer reference](../database-transfers/).

## 4. Assemble and configure the runtime

Use `flat-docroot` when the source has a split WordPress layout. Then call
`apply-runtime` with either the raw filesystem root or the assembled root.
Choose a private output directory for generated runtime files.
[Runtime configuration reference](../runtime/) has complete examples.

If your hosting platform supplies its own configuration, skip generated runtime
files and perform the destination setup explicitly. See
[Prepare a hosting destination](../hosting/).

## Drive the job from results

These commands are separate invocations. Wait for one to complete before
starting the next. Handle exit `2` as continuation, `3` as a caller scheduling
decision after temporary failure, `4` as authorization, and `1` as failure.
Do not loop on every nonzero result indefinitely.

Read each invocation's [command report](../progress-and-results/), not just its
last progress line. A successful preflight is not a completed migration. Keep
the process exit status and handle a missing final report as an unavailable
result. Use `pull-metadata` for saved lifecycle and source details, rather
than parsing private state files.

## Take a final file pass and check the copy

For a completed low-level `files-pull`, abort that completed operation before
starting another delta:

```bash
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --abort
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --progress=jsonl
```

This keeps downloaded files and the retained remote index. It is not a
coordinated database-and-files snapshot. A live cutover still needs a plan for
writes made on the source during migration. Check pages, assets, login, and
site-specific services before directing users to the copy.
