# Reprint handbook

Reprint copies a WordPress site's files and database over HTTP. Use it to make
a local copy for testing, move a site, or add copying to your product. Transfers
stream in chunks and can continue after interruption, without first building
one large archive on the source host.

## Choose your goal

- **View the demo site:** explore [the demo site](https://reprint-demo.wordpress.net/).
  See a sample WordPress journal with posts, photos, and block templates.
- **Clone my site:** follow [Copy a site to your computer](https://developer.wordpress.org/reprint/copy-a-site/).
  This tutorial takes one route: the client, SQLite, and PHP's local server.
- **Integrate Reprint into my product:** start with [Build with Reprint](https://developer.wordpress.org/reprint/build-with-reprint/).
  Run the client from your product and handle access, progress, and results.

The Reprint Server plugin runs on the site being copied. The Reprint client
runs on the computer or host receiving the copy. Installing the plugin does
not start a transfer. A site administrator authorizes the client first; that
administrator does not have to be the person running the client.

The WordPress.org server package provides downloads only. These starting
instructions do not enable changes to the source site's files or database.

## Guides

Choose a guide when you already know the result you want:

- [Copy again or resume](https://developer.wordpress.org/reprint/copy-again/)
- [Copy selected files or the database](https://developer.wordpress.org/reprint/copy-selected-data/)
- [Move to another host](https://developer.wordpress.org/reprint/move-to-another-host/)
- [Copy a Windows-hosted site](https://developer.wordpress.org/reprint/windows-sources/)
- [Move one site out of a network](https://developer.wordpress.org/reprint/multisite/)
- [Rewrite URLs in a live database](https://developer.wordpress.org/reprint/rewrite-urls/)
- [Troubleshooting](https://developer.wordpress.org/reprint/troubleshooting/)

## Build with Reprint

- [Your first integration](https://developer.wordpress.org/reprint/build-with-reprint/)
- [Control each migration step](https://developer.wordpress.org/reprint/integration-pipeline/)
- [Prepare a hosting destination](https://developer.wordpress.org/reprint/hosting/)

## Reference

Look up exact behavior while working:

- [Requirements and downloads](https://developer.wordpress.org/reprint/requirements/)
- [Commands](https://developer.wordpress.org/reprint/commands/)
- [Authentication](https://developer.wordpress.org/reprint/authentication/)
- [Preflight](https://developer.wordpress.org/reprint/preflight/)
- [File transfers](https://developer.wordpress.org/reprint/file-transfers/)
- [Database transfers](https://developer.wordpress.org/reprint/database-transfers/)
- [Runtime configuration](https://developer.wordpress.org/reprint/runtime/)
- [Progress, results, and exit codes](https://developer.wordpress.org/reprint/progress-and-results/)

## Understand Reprint

[How Reprint works](https://developer.wordpress.org/reprint/how-it-works/) explains the client/server split, streaming,
resuming, and the limits of copying a live site.

## Get help

Use the [support forum](https://wordpress.org/support/plugin/reprint/) for help.

## Contribute to Reprint

To change code, tests, or documentation, start with the
[contributor guide](https://developer.wordpress.org/reprint/contributing/).
Code and pull requests live in the [development repository](https://github.com/WordPress/reprint).
