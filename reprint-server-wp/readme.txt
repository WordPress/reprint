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

* [Try Reprint](https://developer.wordpress.org/reprint/getting-started/try-reprint/) to copy the demo before connecting your own site.
* [Clone your site](https://developer.wordpress.org/reprint/getting-started/copy-a-site/) to copy its files and database to the computer you choose.

To start a copy yourself, [Download reprint.phar](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar) and follow [Clone your site](https://developer.wordpress.org/reprint/getting-started/copy-a-site/). You need command-line PHP on the computer receiving the copy. No Reprint account or subscription is required.

= Big sites get across =

Reprint streams files and database rows in parts. Your host does not need to build one large archive before downloading can begin. You need enough space on the receiving computer for the files and database you copy.

= Pick up where you left off =

If your connection drops or the host times out, keep the downloaded files and saved progress. Run the same command again to continue the transfer. You do not need to start the whole copy over.

= Keep links working in the copy =

When your site's address changes, Reprint rewrites links in HTML, block markup, shortcodes, JSON, base64, and serialized PHP data, including data nested inside other formats. It keeps the surrounding data valid, including the lengths stored in serialized PHP values.

See [Database transfers](https://developer.wordpress.org/reprint/reference/database-transfers/) and [File transfers](https://developer.wordpress.org/reprint/reference/file-transfers/) for supported rewriting and its limits.

= Keep your copy up to date =

Run the pull again to bring over files that changed on the source. Unchanged files are skipped, so later file transfers can be much smaller than the first copy.

A database refresh replaces data rather than merging your edits. To update files without replacing the copied database, follow [Copy selected files or the database](https://developer.wordpress.org/reprint/guides/copy-selected-data/).

= Choose what to copy and where =

* Choose MySQL or SQLite for the copied database.
* Copy selected files or only the database when you do not need a full site copy.
* Copy straight to a computer or server with PHP. You do not need WordPress installed at the destination or SSH access to the source site.
* Follow [Move to another host](https://developer.wordpress.org/reprint/guides/move-to-another-host/) when you are ready to move the copy to a new hosting account.

Check [Requirements and downloads](https://developer.wordpress.org/reprint/getting-started/requirements/) for the PHP extensions, database setup, and disk space you need.

= Script copies or build Reprint into your product =

You can run copies from scripts, read exit codes, and show JSON progress to your users. Start with [Build with Reprint](https://developer.wordpress.org/reprint/build-with-reprint/). Use [Control each migration step](https://developer.wordpress.org/reprint/build-with-reprint/integration-pipeline/) when you need to run the steps separately, and [Progress, results, and exit codes](https://developer.wordpress.org/reprint/reference/progress-and-results/) to handle their results.

The client and server are also available as Composer packages. See the [contributor guide](https://developer.wordpress.org/reprint/contributing/) for package and source information, or the [command reference](https://developer.wordpress.org/reprint/reference/commands/) for CLI commands.

Reprint is free and open-source software. The maintained PHP source, license, build instructions, and development are in the [Reprint repository](https://github.com/WordPress/reprint). Both release builds adapt the server code for older PHP versions and bundle its dependencies. You do not need build tools on your site.

== Installation ==

1. On the site you want to copy, open Plugins > Add New Plugin and search for Reprint.
2. Install and activate Reprint. On multisite, a network administrator must network-activate it.
3. Open Tools > Reprint Server. On multisite, use the network settings page.
4. Paste the client's Migration Token and choose Authorize tool. Return to the client to continue the copy. If the settings show a Connection token form instead, follow [Authorize the Reprint client](https://developer.wordpress.org/reprint/getting-started/authorize-client/).

If you have not started a client yet, [Download reprint.phar](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar) and follow [Clone your site](https://developer.wordpress.org/reprint/getting-started/copy-a-site/). The guide includes key creation, authorization, and complete copy commands for SQLite and MySQL.

== Frequently Asked Questions ==

= Which PHP versions can your source site use? =

The released plugin supports downloads on PHP 5.6.20 or newer. Use a maintained PHP version when your host allows it. The separate command-line client needs PHP 7.4 or newer on the computer receiving the copy.

= What does your copy include? =

Your site's files and database: posts, pages, media, users, settings, themes, plugins, and their tables. Reprint skips known caches, logs, backup archives, and temporary files. See [File transfers](https://developer.wordpress.org/reprint/reference/file-transfers/) for file selection and skipped paths.

= Can you copy a site while it is live? =

Yes. The source can keep serving visitors during the copy. It can also keep changing, so the files and database are not one frozen snapshot. For a final move, pause new orders, uploads, and other writes, then take a final copy before switching visitors to the destination. Follow [Move to another host](https://developer.wordpress.org/reprint/guides/move-to-another-host/) for the steps.

= Does activating Reprint start a copy? =

No. Activate the plugin, authorize a client, then start the copy from that client.

= Do you need the command-line client? =

To start a copy yourself, you need the client and PHP 7.4 or newer on the receiving computer. If a client has already given you a Migration Token, you can authorize it in WordPress without installing the command-line client yourself.

= Can you send changes back to the source site? =

The WordPress.org plugin supports downloading files and database data. It does not accept uploads back into the site. Changes you make to the copy stay in that copy.

= Can the copy send email or use live payment settings? =

Once you run the copied site, its plugins may still connect to live email, payment, or other services. Disable those connections or replace them with test settings before using the copy for development.

= Can you copy a site from a multisite network? =

Yes. A network administrator manages access. Follow [Move one site out of a network](https://developer.wordpress.org/reprint/guides/multisite/) for selecting a subsite and checking the copy.

= Who receives your site data? =

Your files and database travel over HTTPS directly to the Reprint client you authorize, without passing through a Reprint cloud service. They may include private content, personal data, password hashes, and configuration secrets. Keep credentials private and store copies somewhere visitors cannot access unless you intend to publish them. See [Authentication](https://developer.wordpress.org/reprint/reference/authentication/) for access and credential details.

= How do you revoke access? =

Remove the client's Migration Token under Tools > Reprint Server. For connection-token access, clear or replace the token. If the settings say your host manages credentials, ask the host to change them. Revoking access leaves existing copies unchanged.

= What happens when you deactivate or delete Reprint? =

Deactivation keeps the settings. Deleting the plugin through WordPress removes its stored settings and credentials. Downloaded copies and files configured separately by your host stay in place.

= Where can you get help? =

Start with [Troubleshooting](https://developer.wordpress.org/reprint/guides/troubleshooting/) or ask in the [WordPress.org support forum](https://wordpress.org/support/plugin/reprint/). For source contributions and reproducible bug reports, use the [development repository](https://github.com/WordPress/reprint).

Keep credentials and private site data out of public reports. Report security vulnerabilities through [WordPress.org's private reporting process](https://developer.wordpress.org/plugins/wordpress-org/reporting-plugin-security-issues/).

== Screenshots ==

1. Authorize a client to copy your site using a Migration Token.

== Changelog ==

= 0.11.4 =

* Published the plugin and command-line client on WordPress.org.
* Added clearer errors when Cloudflare requires a browser challenge, and capped waits when a host limits requests.
* Cleared copied WordPress caches after database import so WordPress can rebuild them for the destination.
