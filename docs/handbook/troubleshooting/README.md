# Troubleshooting

Keep the original command and its error message. Do not delete `state/` as
a first step: it contains credentials and saved transfer progress.

## The client does not start

If you see **command not found: php**, install command-line PHP or use your
development environment's PHP binary. If PHP cannot open `reprint.phar`, check
that your terminal is in the folder where you saved it.

Run `php --version` and `php -m` and compare the output with
[Requirements and downloads](../requirements/). A missing PHP extension must
be enabled for the terminal's PHP, which may differ from your web server's PHP.

## The first command stops after printing a Migration Token

This is expected. The client stops with exit code `4` so an administrator can
[authorize it](../authorize-client/). After authorization, run the same command
again, from the same folder and with the same `--state-dir`.

If authorization still fails, check that you authorized the token printed by
this client for this source site. Existing tokens for other clients do not grant
access to this one. If the host requires a connection token, use the alternative
steps on the authorization page. Update old clients and plugins together if
the error asks for an update.

## The source cannot be reached

Check that the source URL uses HTTPS and that the plugin is active. Copy the
Reprint API URL shown on the plugin settings screen if you are unsure which
address to use.

A login wall, firewall, or security challenge can prevent the client from
reaching the API. Ask the host to allow the Reprint requests. Resolve certificate
errors rather than disabling HTTPS checks for a transfer of real site data.

## A transfer was interrupted

Run the same `pull` command again with the same source address, state
directory, filesystem root, and options. Reprint uses its saved progress.
Read any failure message first; a permission or configuration problem still
needs to be corrected.

If a command reports that it cannot resume a particular database operation,
follow that message's instructions for a fresh import. Do not assume that
every database engine or failure can resume.

## The local server will not start

If port `8881` is already in use, stop the other local server and run:

```bash
bash state/runtime/start.sh
```

Run this from the working folder used in the tutorial. If the file does not
exist, runtime setup has not finished; check the preceding error and resume
the original pull command.

## The local site fails or behaves differently

Check the local server's terminal output for the first PHP error. The local PHP
version, extensions, database, or missing source-host service may be the cause.
SQLite does not make every MySQL-dependent plugin work unchanged.

Check the browser address too. The tutorial rewrites the site to
`http://localhost:8881`; opening the original address still visits the live site.

To restart an already copied site, use `start.sh`. Repeating a completed `pull`
can import the source database again and replace local database changes.

## Ask for help

Ask usage questions in the
[WordPress.org support forum](https://wordpress.org/support/plugin/reprint/).
Report reproducible development bugs in the
[Reprint issue tracker](https://github.com/WordPress/reprint/issues).

Include the client and plugin versions, the command with tokens removed, and
the exact error. Leave out private keys, connection tokens, state directories,
and database dumps. Check logs for private site data before sharing them.
Report security vulnerabilities privately.
