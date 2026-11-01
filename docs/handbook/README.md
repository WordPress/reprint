# Reprint handbook

Reprint copies a WordPress site's files and database over HTTP. Use it to make
a local copy for testing, move a site, or add copying to your product. Transfers
stream in chunks and can continue after interruption, without first building
one large archive on the source host.

## Choose your goal

- **View the demo site:** explore [the demo site](https://reprint-demo.wordpress.net/).
  See a sample WordPress journal with posts, photos, and block templates.
- **Clone my site:** follow [Copy a site to your computer](copy-a-site/).
  This tutorial takes one route: the client, SQLite, and PHP's local server.
- **Integrate Reprint into my product:** start with [Build with Reprint](build-with-reprint/).
  Run the client from your product and handle access, progress, and results.

The Reprint Server plugin runs on the site being copied. The Reprint client
runs on the computer or host receiving the copy. Installing the plugin does
not start a transfer. A site administrator authorizes the client first; that
administrator does not have to be the person running the client.

The WordPress.org server package provides downloads only. These starting
instructions do not enable changes to the source site's files or database.

## Guides

Choose a guide when you already know the result you want:

- [Copy again or resume](copy-again/)
- [Copy selected files or the database](copy-selected-data/)
- [Move to another host](move-to-another-host/)
- [Copy a Windows-hosted site](windows-sources/)
- [Move one site out of a network](multisite/)
- [Rewrite URLs in a live database](rewrite-urls/)
- [Troubleshooting](troubleshooting/)

## Build with Reprint

- [Your first integration](build-with-reprint/)
- [Control each migration step](integration-pipeline/)
- [Prepare a hosting destination](hosting/)

## Reference

Look up exact behavior while working:

- [Requirements and downloads](requirements/)
- [Commands](commands/)
- [Authentication](authentication/)
- [Preflight](preflight/)
- [File transfers](file-transfers/)
- [Database transfers](database-transfers/)
- [Runtime configuration](runtime/)
- [Progress, results, and exit codes](progress-and-results/)

## Understand Reprint

[How Reprint works](how-it-works/) explains the client/server split, streaming,
resuming, and the limits of copying a live site.

## Get help or contribute

Use the [support forum](https://wordpress.org/support/plugin/reprint/) for help.
Development setup, source architecture, and contribution instructions are in
the [development repository](https://github.com/WordPress/reprint).
