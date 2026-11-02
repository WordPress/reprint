# Copy a site to your computer

This tutorial copies one single-site WordPress installation to your computer
and opens it at http://localhost:8881. Choose SQLite if you do not have a local
database server, or MySQL if you already use one.

To try Reprint before connecting your own site, follow
[Try Reprint](try-reprint.md). The steps
below are for a site where you can install the plugin and authorize the client.

You need administrator access to the WordPress site to install the Reprint
plugin. Use a terminal on macOS or Linux, or Windows Subsystem for Linux (WSL).
Check the [requirements and downloads](requirements.md) before continuing.

## 1. Prepare a working folder

Open a terminal on your computer and run:

```sh
mkdir reprint-demo
cd reprint-demo
```

[Download the latest stable Reprint client](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar)
into this folder as `reprint.phar`, or download it from your terminal:

```sh
curl -fL https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar -o reprint.phar
php reprint.phar --version
```

You should see a Reprint version number. If the command fails, follow
[Troubleshooting](troubleshooting.md) before starting a copy.

## 2. Install the plugin on the source site

In the source site's WordPress admin, open **Plugins → Add New Plugin**.
Search for **Reprint**, install it, and activate it. Then open
**Tools → Reprint Server**.

Leave that screen open. The next step gives you the Migration Token to paste
there.

## 3. Start the copy on your computer

Choose one of the commands below. Run it in the same terminal folder and
replace https://example.com with the source site's HTTPS address.

### SQLite

No local database server is needed:

```sh
php reprint.phar pull https://example.com \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite --new-site-url=http://localhost:8881
```

### MySQL

Start your local MySQL server and create an empty database for this copy, such
as `reprint_copy`. Use an account that can create, change, and remove tables
in that database. Importing can replace existing tables.

Replace `reprint_user` and `YOUR_PASSWORD` with your local MySQL credentials.
Change the host and port if your server uses different values:

```sh
php reprint.phar pull https://example.com \
  --state-dir=./state --fs-root=./files \
  --target-engine=mysql --target-host=127.0.0.1 --target-port=3306 \
  --target-db=reprint_copy --target-user=reprint_user --target-pass='YOUR_PASSWORD' \
  --new-site-url=http://localhost:8881
```

### Authorize the client

The client pauses and prints a **Migration Token**. This is expected; you
need to authorize it before copying starts.

1. Copy the printed Migration Token.
2. Paste it into **Tools → Reprint Server** on the source site.
3. Choose **Authorize tool**.
4. Run the same terminal command again.

If the plugin instead shows a **Connection token** form, use the alternative
steps in [Authorize the Reprint client](authorize-client.md), then add
`--secret='TOKEN'` to the pull command using that connection token.

The client now checks the source, downloads files and database data, imports
the database into your chosen local database, rewrites the site URL, and
prepares a local server. Large sites take longer. Keep the terminal open.

## 4. Open and check the copy

When Reprint starts the local server, open the address it prints. For this
tutorial it is http://localhost:8881.

Before testing features that send email, take payments, or call other live
services, disable those connections or replace them with test settings.

Check these on the local address:

1. The home page opens.
2. A few internal links stay on the local site.
3. Images load.
4. You can sign in at `/wp-admin/` with an account from the source site.

Some plugins need MySQL rather than SQLite, or services provided by the source
host. A completed transfer does not establish that every plugin works locally.
If a check fails, see [Troubleshooting](troubleshooting.md).

## 5. Stop and start the local server

Press `Ctrl+C` in the terminal to stop serving the site. Your copy stays on
disk. From the `reprint-demo` folder, start it again with:

```sh
bash state/runtime/start.sh
```

Keep this working folder in place, including `state/` and `files/`.
`state/` contains credentials, transfer progress, the SQL download, and
generated server files.
`files/` contains the downloaded site tree. With SQLite, it also contains the
local database. With MySQL, the database stays in your local MySQL server;
keep that server running when using the copy. Keep both folders private.

To resume an interrupted transfer, run the same `pull` command again with the
same source address and folders. To only restart the local server, use
`start.sh` instead. Running `pull` after completion can replace local database
changes with source data.

Next: [How Reprint works](how-it-works.md), or
[remove client access](authorize-client.md) when you no longer need it.
