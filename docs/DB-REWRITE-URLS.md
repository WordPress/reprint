# Rewrite URLs in a live database

Use `db-rewrite-urls` to replace URLs in an existing MySQL or SQLite database.
The command works directly on the database. It does not export or re-import a
SQL file.

It updates one row at a time and saves its place as it goes. It does not lock a
whole table for the duration of the command.

## Run the command

```bash
php reprint.phar db-rewrite-urls \
  --state-dir=/path/to/reprint-state \
  --rewrite-url https://old.example https://new.example
```

Add another `--rewrite-url FROM TO` for each additional replacement.

The command uses the database previously saved by `db-apply`. You can use the
`--target-*` options to choose a different database.

You do not need to pass a remote Reprint API URL when the state directory has
one saved remote. If it has no saved remote, the command uses local state. This
command does not use `--fs-root`.

## Stop and resume

Press `Ctrl+C` once to stop safely when PHP PCNTL is available. The command
finishes the current row, saves its place, and exits. Run the same command again
to continue.

If the process crashes or is killed, run the same command again. The command
checks the row it was working on:

* If the row was not updated, it updates it after resuming.
* If the row was already updated, it moves on without replacing the URL again.

This assumes that completed database writes survived the crash and no other
connection changed the same row at that moment. A power, host, or database
failure may have different results.

While a run is incomplete, keep using the same URL replacements and database.
To discard the saved place and start over with different settings, use
`--abort`.

## Tables without primary keys

A primary key lets the command identify each row and remember where to resume.
If a table has no primary key, the command warns you, leaves that table
unchanged, and continues with the next table. It also records the skipped table
in the audit log.

## Changes made by the site while the command runs

The site may change or delete a row after the command reads it. The command will
not overwrite that newer change. It leaves the row alone and moves on.

This protects a row from being replaced twice. That matters when the new URL
contains the old URL, for example:

```text
https://example.com -> https://example.com/new
```

The tradeoff is that a value written at the same time may still contain the old
URL. If you need to catch every value, prevent the site from writing to the
database while this command runs.

## Rows to verify

Every run creates a `db-rewrite-urls-<job-id>.jsonl` report in the same
directory as its saved progress. The first line records the URL replacements
and database used by the job.

When the command cannot tell whether it updated a row before an interruption or
another connection changed that row, it adds the table, primary key, and column
hashes to the report. It does not copy the full database value into the file.

The final command output tells you how many rows need verification and where to
find the report. A reported row may already contain the intended result. Check
it before applying another replacement.
