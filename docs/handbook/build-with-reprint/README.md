# Build with Reprint

Use the client to add WordPress copying to a migration tool or hosting platform.
Your tool runs the client; a site administrator installs Reprint Server and
authorizes it on the source site.

First complete [Copy a site to your computer](../copy-a-site/). It shows the
access steps your users will also need. The WordPress.org server package
provides downloads only; do not offer push using that package.

## Start with the high-level commands

| Command | Work performed |
| --- | --- |
| `pull` | Preflight, files, database download and apply, and runtime setup. |
| `pull-files` | Preflight and file download, without database import or runtime setup. |
| `pull-db` | Preflight, database download, and database apply. |
| `pull-metadata` | Read saved pull lifecycle and source-site details without a network request. |

Use one state directory for each local filesystem root. Reuse the same source
address and state directory to resume that transfer. Keep state and credentials
outside the downloaded site and outside web-served directories.

For a MySQL destination, choose a dedicated empty database and supply the
target connection options. Database apply can replace existing target data.
Check the options supported by the client you ship:

```bash
php reprint.phar pull --help
php reprint.phar pull-db --help
php reprint.phar pull-metadata --help
```

## Run without starting a development server

To prepare the same local SQLite copy as the tutorial, but return control to
your tool instead of starting the server:

```bash
php reprint.phar pull https://example.com \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite --new-site-url=http://localhost:8881 \
  --start-runtime=none --progress=jsonl
```

Handle the initial authorization stop as a request for user action. Show the
Migration Token, send the administrator to Reprint Server settings, and run the
same command after authorization. Do not treat the settings confirmation as
confirmation that a transfer completed.

## Report progress and handle exits

Use `--progress=jsonl` for structured progress. SQL or other command data may
have its own output format; do not assume every command prints only progress.
Use `pull-metadata` for saved lifecycle details rather than depending on private
state-file layouts.

Read the final result and exit status. A command may complete, need another
bounded run, require user action, stop after a temporary failure, or fail.
Do not treat every nonzero exit as permission to retry forever. Keep the
command's state, show the reason, and let your caller decide when to run again.

The smaller commands, such as `preflight`, `files-pull`, `db-pull`, `db-apply`,
and `apply-runtime`, are available when your platform needs to control each
stage. Consult each command's `--help` before building that pipeline.

For package source, embedding details, tests, and contributions, use the
[development repository](https://github.com/WordPress/reprint). Get user-facing
downloads from [WordPress.org](../requirements/).
