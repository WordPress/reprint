# Reprint handbook

Reprint copies a WordPress site's database and files over HTTP. Use it to
prepare a local copy for testing, or as part of a migration to another host.
Transfers stream in chunks and can resume after interruption, without first
building one large archive on the source host.

## Choose your starting point

- **A tool asked you to install Reprint:** follow
  [Authorize a tool to copy your site](authorize-a-tool/). You can do this in
  WordPress admin. You do not need to install the command-line client.
- **You want a local copy:** follow
  [Copy a site to your computer](copy-a-site/). This tutorial uses the Reprint
  client, SQLite, and PHP's local development server.
- **You are building a tool or hosting integration:** start with
  [Build with Reprint](build-with-reprint/).

## The two parts

The **Reprint Server plugin** runs on the WordPress site being copied. The
**Reprint client** runs on the computer or host receiving the copy.

```text
Existing WordPress site                   Your computer or destination host
Reprint Server plugin  -- HTTPS data -->  Reprint client
```

Installing the plugin does not start a copy. You authorize a client, then
start the transfer from that client or the tool using it.

The WordPress.org plugin provides downloads only. These getting-started
instructions do not enable changes to the source site's files or database.

## Before copying real data

A copy can contain personal data, password hashes, and configuration secrets.
Only authorize tools you trust. Keep downloaded copies and client credentials
private, and use HTTPS for remote transfers.

A copied site may still contact live services, including email providers,
payment systems, and webhooks. Reprint is not a sandbox for those services.
Disable or replace those connections before using real site data locally.

## More information

- [Requirements and downloads](requirements/)
- [Troubleshooting](troubleshooting/)
- [How Reprint works](how-it-works/)
- [Reprint on WordPress.org](https://wordpress.org/plugins/reprint/)
- [Development and contributions](https://github.com/WordPress/reprint)
