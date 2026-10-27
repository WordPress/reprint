# Saved remote settings

Save a site once, then run commands without repeating its URL, paths, and mappings:

```bash
reprint remote add source https://example.com \
  --fs-root=./files \
  --target-engine=sqlite \
  --rewrite-url https://example.com http://localhost:8881

reprint keygen --remote=source
# Enroll the printed Migration Token under Tools > Reprint Server.
reprint preflight --remote=source
reprint pull --remote=source
reprint files-diff --remote=source
reprint files-push --remote=source
```

`keygen --remote=source` writes `key.pem` in the named remote state directory.
After enrollment, pull and push use that file. A first `pull` without credentials
also generates its enrollment key there. To reuse an existing key file, save
`--private-key-path=FILE` with `remote add` instead.

On hosts without OpenSSL, use `--secret-file=FILE` for the legacy connection
token. Keep credential files outside the filesystem root and out of version
control. Only commands that use credentials read them; `files-diff` and
`config show` do not. Explicit credentials replace a saved credential-file
setting for that invocation without editing the config.

## What gets saved

`remote add` creates `.reprint/config.json` in the current directory. It makes
no request and does not create the filesystem root. There is no separate
`init` step. One config supports one named remote; a second `remote add` fails
without replacing the first.

The JSON has a `remote` section with `name`, `remote_reprint_api_url`, and
`options`, plus a `local` section. Option keys use their CLI spelling without
`--`. Repeated options are arrays; `remap` and `rewrite-url` are arrays of
`[FROM, TO]` pairs. Edit this file to change saved defaults.

Reusable settings include the filesystem root and state directory, credential-file
path, pull selections and mappings, database target, and runtime settings.
Action flags such as `--abort`, `--commit`, and `--force` cannot be saved.
Neither can `--insecure`, raw secrets, or database passwords. Pass those only
when needed. `--new-site-url` cannot be saved: use explicit `--rewrite-url FROM TO`
pairs so changing the API address cannot change their source URL.

The config file is written with mode `0600`. A newly created config directory
uses `0700`. Treat `.reprint/` as private local migration data, not site content.
Both the config directory and state directory must be outside the filesystem
root. A supplied state directory must be empty; this command does not adopt
state from earlier URL-based commands.

## Selecting settings

Transfer commands load saved settings only with `--remote=NAME` or `--config=FILE`.
`--remote=source` reads `.reprint/config.json` in the current directory and checks
that its remote is named `source`. There is no search through parent directories
and no global config. A missing URL alone never loads a config.

```bash
reprint pull --remote=source
reprint files-pull --remote=source --include :wp-uploads:
reprint pull --config=/work/example/.reprint/config.json
reprint config show --command=files-pull
reprint config show --command=files-pull --include :wp-uploads:
```

`config show` prints the selected address, resolved paths, applicable parsed
options, and the source of each supplied option. It does not contact the site
or read secret files. Password values passed on the command line are redacted.
It shows command inputs, not the full persisted state of a resumed operation.

Explicit CLI options replace the matching saved option for that invocation.
A repeated option replaces its entire saved list rather than appending to it.
Overrides do not edit the config. Existing resume rules still apply: some
settings are retained by command state or cannot change during unfinished work.
`--fs-root` and `--state-dir` cannot override a saved remote to use another
location. Use a separate config and state directory for another local copy.

Paths supplied to `remote add` are resolved against the current directory and
saved as absolute paths. For `config.json`, the default `state-dir` is `state`, relative to the
config directory. Other config filenames get `<filename>.state`; two custom
config files in one directory therefore get separate default state directories. If you edit a saved local path to be relative, it is always
resolved against the config directory, regardless of where you run Reprint.
Paths supplied directly to a later command keep their existing CLI meaning.

An explicit positional URL never loads config settings. It cannot be combined
with `--remote` or `--config`. `--no-config` also prevents config loading and
cannot be combined with either selector. Existing scripts that supply a URL,
state directory, and filesystem root keep their URL-based state layout.

For example, `reprint pull https://example.com --fs-root=./files` fails because
`--state-dir` is missing, even if a nearby config supplies it. Optional arguments
keep their normal command defaults rather than saved config values.

`recover` and `post-process` continue to require explicit arguments. Their
filesystem root is the ready-to-run WordPress directory, not the raw download
root saved here. `apply-runtime --flat-document-root=DIR` can use saved settings
without also receiving the saved `--fs-root`.

Saved pull URL rewrites are **not** applied to `db-push`, and are never reversed
automatically. Supply its local-to-hosted `--rewrite-url FROM TO` pairs explicitly.
Other settings are supplied only to commands that accept them. For example,
`files-diff` gets no secret or database options.

## Changing the remote Reprint API URL

Suppose `https://example.com` starts redirecting to `https://www.example.com`.
The request fails and reports the redirect. Reprint does not follow it or edit
the config.

```bash
reprint remote set-url source https://www.example.com
reprint preflight --remote=source
```

This changes only the saved address. It does not change URL rewrites, path
mappings, the remote name, or completed indexes. Named remote state lives at
`<state-dir>/remotes/source/`, so the new address uses the same local index.
Explicit URL commands still use the URL hash instead of a name.

When a local index, remote index, or downloaded `db.sql` already exists,
`set-url` requires `--same-remote`. This confirms that the new address serves
the same remote. Use a separate config for a different site; matching paths
alone cannot establish that two sites are the same.

An unfinished pull, files-push, or database push blocks the change. Finish or
explicitly abort it at the old address first. A committed database push must
finish `db-push --remote=source --cleanup` before changing the address. Reprint keeps its local
session until the target confirms cleanup or discard.

Preflight at the new address compares the document root, path format, and
WordPress directory paths with the previous successful report. Different paths
require a separate config. Failed attempts remain visible as failed reports;
a small fingerprint retains the last successful path check across retries and
process exits. A failure at either address cannot erase that check. Commands
that use saved preflight refuse the old-address report;
`db-push` also requires a fresh preflight when a saved report names the old URL.
High-level `pull` runs its normal preflight stage.

Config commands and configured transfers hold the `config.json.lock` lock. A
second command cannot replace settings while the first is still running.
