# Copy again or resume

Keep the state directory and downloaded files from your first copy. The same
source address selects the same saved remote state. A different filesystem
root needs a different state directory.

## Continue an interrupted copy

Run the same command with the same options. For the local-copy tutorial:

```sh
php reprint.phar pull https://example.com \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite --new-site-url=http://localhost:8881
```

Do not delete state or use `--abort` just to continue. The client needs the saved
positions and credentials. Check [Progress, results, and exit codes](https://developer.wordpress.org/reprint/progress-and-results/)
when running commands from a script.

## Reopen the copy without downloading again

Run the generated `start.sh` whose path the original pull printed. Repeating a
completed full `pull` can replace local database data; it is not just a way to
restart the web server.

## Fetch later file changes

```sh
php reprint.phar pull-files https://example.com \
  --state-dir=./state --fs-root=./files
```

The default **catch-up** mode applies remote paths that changed since the last
completed pull. A locally edited `style.css` stays if its remote index entry
did not change. If that remote entry changes too, the local edit can be replaced.
Reprint does not combine the edits. Local-only files remain.

Use `pull-files` rather than full `pull` when you want to keep the local database.

## Make a selected local tree match the source

Use **mirror** only when remote values should replace local values inside the
current selection. For example, to mirror one theme:

```sh
php reprint.phar pull-files https://example.com \
  --state-dir=./state --fs-root=./files \
  --mode=mirror --include=:wp-content:/themes/example
```

Paths inside today's mirror scope must match today's remote index;
remote-absent local paths are removed. Paths outside the current selection
are not changed. `--on-fs-root-nonempty=preserve-local` also preserves paths
that existed before the first pull.

The mode is saved for an unfinished file pull. To change it, read that
command's `--help`, abort the unfinished operation, then start with the new
mode. Low-level `files-pull` also needs `--abort` after completion before
starting another delta; the high-level `pull-files` handles repeat runs.

Keep the original URL mappings when reusing the saved file index. Different
mappings need a fresh state directory and empty filesystem root. A later
database-only URL rewrite does not rewrite previously downloaded files.

Check changed pages and assets after the transfer. Files changing on the
source during the copy may still need another pass.
