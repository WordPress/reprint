# Clone your site

Run Reprint on the computer that should receive your site's files and database. You can use your laptop or a remote server with command-line PHP. Choose SQLite if you do not have a database server, or MySQL if you already use one.

These steps copy a single-site WordPress installation and start a development server so you can check it. To serve the copy publicly at a domain, follow [Move to another host](https://developer.wordpress.org/reprint/guides/move-to-another-host/).

To try Reprint before connecting your own site, follow [Try Reprint](https://developer.wordpress.org/reprint/getting-started/try-reprint/). The steps below are for a site where you can install the plugin and authorize the client.

You need administrator access to the WordPress site to install the Reprint plugin. Use a terminal on macOS or Linux, or Windows Subsystem for Linux (WSL). Check the [requirements and downloads](https://developer.wordpress.org/reprint/getting-started/requirements/) before continuing.

## 1. Prepare a working folder

Open a terminal on the destination computer and run:

```sh
mkdir reprint-demo
cd reprint-demo
```

[Download the latest stable Reprint client](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar) into this folder as `reprint.phar`, or download it from your terminal:

```sh
curl -fL https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar -o reprint.phar
php reprint.phar --version
```

You should see a Reprint version number. If the command fails, follow [Troubleshooting](https://developer.wordpress.org/reprint/guides/troubleshooting/) before starting a copy.

## 2. Install the plugin on the source site

In the source site's WordPress admin, open **Plugins → Add New Plugin**. Search for [Reprint](https://wordpress.org/plugins/reprint/), install it, and activate it. Then open **Tools → Reprint Server**.

Leave that screen open for the authorization step below.

## 3. Authorize the client

If the plugin shows a **Migration Token** form, generate a key on the destination. Replace https://example.com with the source site's HTTPS address:

```sh
php reprint.phar keygen https://example.com \
  --state-dir=./state --out=./reprint-key.pem
```

1. Copy the printed Migration Token.
2. Paste it into **Tools → Reprint Server** on the source site.
3. Choose **Authorize client**.

The private key stays in `reprint-key.pem` on the destination. The copy commands below pass that file with `--private-key-path=./reprint-key.pem`.

Running `pull` without a credential does the same work in one step: the client generates a key inside `state/`, prints the Migration Token, and stops with exit code `4`. Authorize the token, then run the same `pull` again. This guide creates the key file first so you know where it is and can pass it to every later command.

If the plugin instead shows a **Connection token** form, follow the [connection-token steps](https://developer.wordpress.org/reprint/getting-started/authorize-client/#if-the-screen-asks-for-a-connection-token). Replace the private-key flag in the copy command with `--secret='TOKEN'`, using the saved connection token. Use one credential flag, not both.

## 4. Start the copy

Choose one of the commands below. Run it in the same terminal folder and replace https://example.com with the source site's HTTPS address.

### SQLite

No separate database server is needed:

```sh
php reprint.phar pull https://example.com \
  --private-key-path=./reprint-key.pem \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite --new-site-url=http://localhost:8881
```

### MySQL

Start a MySQL server you can connect to and create an empty database for this copy, such as `reprint_copy`. Use an account that can create, change, and remove tables in that database. Importing can replace existing tables.

Replace `reprint_user` and `YOUR_PASSWORD` with your destination database credentials. The example connects to MySQL on the same computer. Change the host and port if your database server is elsewhere:

```sh
php reprint.phar pull https://example.com \
  --private-key-path=./reprint-key.pem \
  --state-dir=./state --fs-root=./files \
  --target-engine=mysql --target-host=127.0.0.1 --target-port=3306 \
  --target-db=reprint_copy --target-user=reprint_user --target-pass='YOUR_PASSWORD' \
  --new-site-url=http://localhost:8881
```

The client now checks the source, downloads files and database data, imports the database into your chosen database, rewrites the site URL, and starts the development server. Large sites take longer. Keep the terminal open.

## 5. Open and check the copy

The examples start WordPress at http://localhost:8881 on the destination computer. Open that address in a browser on the same computer.

Before testing features that send email, take payments, or call other live services, disable those connections or replace them with test settings.

Check these on the preview address:

1. The home page opens.
2. A few internal links stay on the copy.
3. Images load.
4. You can sign in at `/wp-admin/` with an account from the source site.

Some plugins need MySQL rather than SQLite, or services provided by the source host. A completed transfer does not establish that every plugin works on the destination. If a check fails, see [Troubleshooting](https://developer.wordpress.org/reprint/guides/troubleshooting/).

## 6. Stop and start the development server

Press `Ctrl+C` in the terminal running Reprint to stop serving the site. Your copy stays on disk. From the destination's `reprint-demo` folder, start it again with:

```sh
bash state/runtime/start.sh
```

Keep this working folder in place, including `reprint-key.pem`, `state/`, and `files/`. `state/` contains credentials, transfer progress, the SQL download, and generated server files. `files/` contains the downloaded site tree. With SQLite, it also contains the SQLite database. With MySQL, the database stays in your chosen MySQL server; keep that server running when using the copy. Keep both folders private.

To resume an interrupted transfer, run the same `pull` command again with the same source address and folders. To only restart the development server, use `start.sh` instead. Running `pull` after completion can replace destination database changes with source data.

Next: [How Reprint works](https://developer.wordpress.org/reprint/understand-reprint/how-it-works/), or [remove client access](https://developer.wordpress.org/reprint/getting-started/authorize-client/) when you no longer need it.
