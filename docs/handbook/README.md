# Reprint handbook

Reprint copies a WordPress site's database and files over HTTP. Use it to
prepare a local copy for testing, or as part of a migration to another host.
Transfers stream in chunks and can resume after interruption, without first
building one large archive on the source host.

## Choose your starting point

- **Explore a demo site:** visit [reprint-demo.wordpress.net](https://reprint-demo.wordpress.net/),
  a sample WordPress journal with posts, photos, and block templates.
- **Copy your site to your computer:** follow
  [Copy a site to your computer](copy-a-site/). This tutorial uses the Reprint
  client, SQLite, and PHP's local development server.
- **Connect the Reprint client to your site:** follow
  [Authorize the Reprint client](authorize-client/) to save its Migration Token
  in WordPress admin.
- **Integrate Reprint in your product:** start with
  [Build with Reprint](build-with-reprint/).

## The two parts

The **Reprint Server plugin** runs on the WordPress site being copied. The
**Reprint client** runs on the computer or host receiving the copy.

```text
Existing WordPress site                   Your computer or destination host
Reprint Server plugin  -- HTTPS data -->  Reprint client
```

Installing the plugin does not start a copy. You authorize a client, then
start the transfer from that client.

The WordPress.org plugin provides downloads only. These getting-started
instructions do not enable changes to the source site's files or database.

## More information

- [Requirements and downloads](requirements/)
- [Troubleshooting](troubleshooting/)
- [How Reprint works](how-it-works/)
- [Reprint on WordPress.org](https://wordpress.org/plugins/reprint/)
- [Development and contributions](https://github.com/WordPress/reprint)
