# Control each migration step

Start with [Build with Reprint](https://developer.wordpress.org/reprint/build-with-reprint/). Its high-level `pull`
example is enough when your product does not need separate stages. This guide
is for products that must choose when to index, copy, import, and start WordPress.

The examples use `$URL`, `$STATE_DIR`, and `$FS_ROOT` as described in
[Conventions](https://developer.wordpress.org/reprint/commands/#conventions). Keep state outside every web-served
directory. The same source address selects the same saved remote state.

## 1. Authorize and inspect the source

Generate and enroll a Migration Token before starting low-level requests:

```bash
php reprint.phar keygen "$URL" --state-dir="$STATE_DIR" \
  --out=./reprint-key.pem --progress=jsonl
```

Have the administrator authorize the printed Migration Token before continuing;
[Authorize from your product](https://developer.wordpress.org/reprint/build-with-reprint/#authorize-from-your-product)
shows how to keep that step inside your interface:

```bash
php reprint.phar preflight "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --progress=jsonl
php reprint.phar preflight-assert "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --progress=jsonl
```

If the source uses a connection token, skip key generation and replace
`--private-key-path=./reprint-key.pem` with `--secret='TOKEN'` on each command
that contacts the source. Local commands such as `preflight-assert` and
`db-apply` need no source credential. See [Authentication](https://developer.wordpress.org/reprint/authentication/)
and [Preflight](https://developer.wordpress.org/reprint/preflight/).

## 2. Download files

```bash
php reprint.phar files-pull "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --progress=jsonl
```

Choose filters and path selections before the run. The download can keep the
source host's original layout; a later step can assemble a conventional root.
See [File transfer reference](https://developer.wordpress.org/reprint/file-transfers/).

## 3. Download and apply the database

```bash
php reprint.phar db-pull "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --progress=jsonl
php reprint.phar db-apply "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
  --target-engine=sqlite --new-site-url=http://localhost:8881 --progress=jsonl
```

Use destination-specific MySQL options instead when needed. Database apply can
replace existing data. Choose the target database and URL replacements before
starting; keep those choices while resuming. Supply file URL mappings to
`files-pull` too when CSS assets need rewriting. Later database rewriting does
not change already downloaded files.
See [Database transfer reference](https://developer.wordpress.org/reprint/database-transfers/).

## 4. Assemble and configure the runtime

Use `flat-docroot` when the source has a split WordPress layout. Then call
`apply-runtime` with either the raw filesystem root or the assembled root.
Choose a private output directory for generated runtime files.
[Runtime configuration reference](https://developer.wordpress.org/reprint/runtime/) has complete examples.

If your hosting platform supplies its own configuration, skip generated runtime
files and perform the destination setup explicitly. See
[Prepare a hosting destination](https://developer.wordpress.org/reprint/hosting/).

## Drive the job from results

These commands are separate invocations. Wait for one to complete before
starting the next. Handle exit `2` as continuation, `3` as a caller scheduling
decision after temporary failure, `4` as authorization, and `1` as failure.
Do not loop on every nonzero result indefinitely.

Read each invocation's [command report](https://developer.wordpress.org/reprint/progress-and-results/), not just its
last progress line. A successful preflight is not a completed migration. Keep
the process exit status and handle a missing final report as an unavailable
result. Use `pull-metadata` for saved lifecycle and source details, rather
than parsing private state files.

## Take a final file pass and check the copy

For a completed low-level `files-pull`, abort that completed operation before
starting another delta:

```bash
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --abort
php reprint.phar files-pull "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --progress=jsonl
```

This keeps downloaded files and the retained remote index. It is not a
coordinated database-and-files snapshot. A live cutover still needs a plan for
writes made on the source during migration. Check pages, assets, login, and
site-specific services before directing users to the copy.
