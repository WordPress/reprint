=== Reprint Server ===
Contributors: adamziel
Tags: migration, backup, development, synchronization
Requires at least: 4.7
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 0.10.14-dev
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export a WordPress site's database and files to an authorized Reprint client for migration, backups, and local development.

== Description ==

Reprint Server connects your WordPress site to the open-source Reprint command-line client. The client can download the site's database and files, build a local copy, and synchronize files with the source site.

Transfers stream in chunks and can resume after interruption. You do not need to create one large backup archive on your hosting account.

The plugin provides the server API and its settings page. You need the separate [Reprint client](https://github.com/WordPress/reprint/releases/latest) to run transfers. There is no hosted Reprint account or subscription requirement.

= Authentication and push access =

On hosts with OpenSSL, enroll the client's public key under Tools > Reprint Server. The client keeps the private key and signs its requests. Hosts without OpenSSL use a connection token instead.

New keys and connection tokens authorize downloads only. File push requires a separate administrator grant. While enabled, an authorized client can upload, replace, and delete files in the site's document root, except excluded paths. Hosting providers can manage this permission centrally.

Push requires PHP 7.2 or newer and is not supported on multisite networks. The generated release ZIP supports downloads on PHP 5.6.20 or newer; use a maintained PHP version when your host allows it.

= Site data and privacy =

The plugin sends site data to the Reprint client you authorize. Exported databases and files may contain personal data, password hashes, and configuration secrets. Treat client credentials and downloaded site copies as private. Use HTTPS for remote transfers, and remove client access when it is no longer needed.

On multisite, network administrators manage access. Enrolled keys or the network connection token can authorize downloads from sites in that network. See the [multisite documentation](https://github.com/WordPress/reprint#multisite) before transferring a network or an individual site.

= Source and build instructions =

The maintained PHP source, license, and build tools are available in the [Reprint repository](https://github.com/WordPress/reprint). Release builds downgrade a copy of the server's PHP syntax for older hosting environments and bundle the server package with Composer. No build tools are required on the installed site.

To build from source, install the Composer dependencies in tools/php56-build and run bin/build-server-plugin.sh. See reprint-server-wp/README.md in the repository for integration and hosting configuration.

== Installation ==

1. Download reprint-exporter-wp.zip from the [GitHub releases](https://github.com/WordPress/reprint/releases/latest).
2. In WordPress, open Plugins > Add New > Upload Plugin, upload the ZIP, and activate Reprint Server. On multisite, network-activate the plugin.
3. Download reprint.phar from the same release. The client requires PHP 7.4 or newer; file push from the client requires PHP 8.1 or newer.
4. Run the pull command below. On a host with OpenSSL, the client prints the public key to enroll and stops. Enroll it under Tools > Reprint Server, then run the same command again. On multisite, use the network settings page.

    php reprint.phar pull https://example.com --state-dir=./state --fs-root=./files

On a host without OpenSSL, save a connection token in the plugin settings and pass it to the client with --secret=TOKEN. See the [client documentation](https://github.com/WordPress/reprint#authentication) for command options and credential storage.

== Frequently Asked Questions ==

= Does activating the plugin start a transfer? =

No. Configure client access, then start the transfer from the Reprint client. Activation alone does not authorize a client or grant push access.

= Can I migrate this site without enabling push? =

Yes. Downloads are enough to copy this site to another host or a local development environment. Enable push only when you intend to change files on this site.

= How do I revoke access? =

Remove an enrolled public key in Tools > Reprint Server. On a host using connection tokens, clear or replace the token; replacing it also revokes its local push grant. Credentials supplied by your host through public-keys.php, secret.php, or managed configuration must be changed by the host.

= What happens when I deactivate or delete the plugin? =

Deactivation keeps the settings. Deleting the plugin through WordPress removes its stored credentials, push grants, and activation transient, including legacy settings. It does not delete migrated site files, downloaded client copies, or host-configured files and private transfer directories outside the plugin directory.

= Where can I report a problem? =

Use the [Reprint issue tracker](https://github.com/WordPress/reprint/issues) for reproducible bugs and feature requests. Do not include private keys, connection tokens, private site data, or database credentials in a public report. Do not post security vulnerability details in a public issue.

== Changelog ==

See the [GitHub release notes](https://github.com/WordPress/reprint/releases) for the changes in each release.
