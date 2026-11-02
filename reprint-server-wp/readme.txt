=== Reprint ===
Contributors: zieladam, wordpressdotorg
Tags: migration, backup, development, synchronization
Requires at least: 4.7
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 0.11.5-dev
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Copy your WordPress site's files and database to another computer or host with an authorized Reprint client.

== Description ==

Copy your WordPress site to your laptop, a development server, or another host.

Install Reprint on the site you want to copy. A Reprint client downloads its files and database over HTTPS. You authorize the client in WordPress before it can access your site.

= Choose your next step =

* [Try Reprint](https://developer.wordpress.org/reprint/try-reprint/) to copy the demo before connecting your own site.
* [Clone your site](https://developer.wordpress.org/reprint/copy-a-site/) to copy its files and database to the computer you choose.

To start a copy yourself, [Download reprint.phar](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar) and follow [Clone your site](https://developer.wordpress.org/reprint/copy-a-site/). You need command-line PHP on the computer receiving the copy. No Reprint account or subscription is required.

= Why use Reprint? =

* Copy files and database data over HTTPS. You do not need SSH access to the source site.
* Stream the data without first creating a large archive on your hosting account.
* Continue an interrupted transfer with the same command and saved progress.
* Choose SQLite or MySQL for the copied database.
* Copy selected files or the database when you do not need a full site copy.

See [Requirements and downloads](https://developer.wordpress.org/reprint/requirements/) to check your setup, or [Move to another host](https://developer.wordpress.org/reprint/move-to-another-host/) to prepare a copy for a new hosting account.

= Build with Reprint =

You can run the client from your product and read its progress and results. Start with [Build with Reprint](https://developer.wordpress.org/reprint/build-with-reprint/), then use the [command reference](https://developer.wordpress.org/reprint/commands/) for individual commands.

Reprint is open-source software. Development and contributions happen in the [Reprint repository](https://github.com/WordPress/reprint).

== Installation ==

1. On the site you want to copy, open Plugins > Add New Plugin and search for Reprint.
2. Install and activate Reprint. On multisite, a network administrator must network-activate it.
3. Open Tools > Reprint Server. On multisite, use the network settings page.
4. Paste the client's Migration Token and choose Authorize tool. Return to the client to continue the copy. If the settings show a Connection token form instead, follow [Authorize the Reprint client](https://developer.wordpress.org/reprint/authorize-client/).

If you have not started a client yet, [Download reprint.phar](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar) and follow [Clone your site](https://developer.wordpress.org/reprint/copy-a-site/). The guide includes key creation, authorization, and complete copy commands for SQLite and MySQL.

== Frequently Asked Questions ==

= Does activating Reprint start a copy? =

No. Activate the plugin, authorize a client, then start the copy from that client.

= Do you need the command-line client? =

To start a copy yourself, you need the client and PHP 7.4 or newer on the receiving computer. If a client has already given you a Migration Token, you can authorize it in WordPress without installing the command-line client yourself.

= Can you send changes back to the source site? =

The WordPress.org plugin supports downloading files and database data. It does not accept uploads back into the site. Changes you make to the copy stay in that copy.

Copied plugins may still connect to live email, payment, or other services. Replace those connections with test settings before using the copy for development.

= Can you copy a site from a multisite network? =

Yes. A network administrator manages access. Follow [Move one site out of a network](https://developer.wordpress.org/reprint/multisite/) for selecting a subsite and checking the copy.

= Who receives your site data? =

The Reprint client you authorize receives the files and database. These may include private content, personal data, password hashes, and configuration secrets. Keep credentials and downloaded copies private. See [Authentication](https://developer.wordpress.org/reprint/authentication/) for access and credential details.

= How do you revoke access? =

Remove the client's Migration Token under Tools > Reprint Server. For connection-token access, clear or replace the token. If the settings say your host manages credentials, ask the host to change them. Revoking access leaves existing copies unchanged.

= What happens when you deactivate or delete Reprint? =

Deactivation keeps the settings. Deleting the plugin through WordPress removes its stored settings and credentials. Downloaded copies and files configured separately by your host stay in place.

= Where can you get help? =

Start with [Troubleshooting](https://developer.wordpress.org/reprint/troubleshooting/) or ask in the [WordPress.org support forum](https://wordpress.org/support/plugin/reprint/). For source contributions and reproducible bug reports, use the [development repository](https://github.com/WordPress/reprint).

Keep credentials and private site data out of public reports. Report security vulnerabilities through [WordPress.org's private reporting process](https://developer.wordpress.org/plugins/wordpress-org/reporting-plugin-security-issues/).

== Screenshots ==

1. Authorize a client to copy your site using a Migration Token.

== Changelog ==

= 0.11.4 =

* Published the plugin and command-line client on WordPress.org.
* Added clearer errors when Cloudflare requires a browser challenge, and capped waits when a host limits requests.
* Cleared copied WordPress caches after database import so WordPress can rebuild them for the destination.
