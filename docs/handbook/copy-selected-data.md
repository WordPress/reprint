# Copy selected files or the database

Use this guide when you need only part of a site rather than another full copy.
First [install the plugin and authorize the client](authorize-client.md).
Keep the source address and state directory used for that authorization.

The examples use https://example.com, `./state`, and `./files`. Replace them
with your source address and directories. Keep state outside the downloaded tree.

## Copy files without importing the database

```sh
php reprint.phar pull-files https://example.com \
  --state-dir=./state --fs-root=./files
```

This runs preflight and downloads files. It does not import the database or
generate runtime configuration. If it is interrupted, run the same command again.

To copy WordPress content but omit uploads:

```sh
php reprint.phar pull-files https://example.com \
  --state-dir=./state --fs-root=./files \
  --include=:wp-content: --exclude=:wp-uploads:
```

Repeat `--include` and `--exclude` for more source prefixes. Exclusions win.
Built-in skipped paths cannot be added back with an include rule.
See [File transfer reference](file-transfers.md) for filters, symlinks, and
host-plugin selection.

Check the selected files in the downloaded tree. This does not make a working
WordPress copy unless its database and runtime are also available.

## Refresh only the copied database

Use this with the files from an existing copy:

```sh
php reprint.phar pull-db https://example.com \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite --new-site-url=http://localhost:8881
```

This runs preflight, downloads the database, and applies it. It does not update
files or runtime configuration. Applying the database can replace local data;
save local work before refreshing it. Keep the existing target connection and
URL choices when resuming an unfinished import.

Use [Database transfer reference](database-transfers.md) to download a SQL
dump without applying it or to choose a MySQL destination.

After the command completes, open the copied site and check its pages and login.
If a different pull command is unfinished, finish or abort that command before
starting another pipeline.
