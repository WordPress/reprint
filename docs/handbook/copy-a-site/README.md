# Copy a site to your computer

This tutorial copies one single-site WordPress installation to your computer
and opens it at `http://localhost:8881`. It uses SQLite, so you do not need a
local MySQL server.

Start with a test site. You need permission to copy it and administrator
access to install its plugin. The terminal examples use Bash on macOS, Linux,
or Windows Subsystem for Linux (WSL). Check the
[requirements and downloads](../requirements/) before continuing.

## 1. Prepare a working folder

Open a terminal on your computer and run:

```bash
mkdir reprint-demo
cd reprint-demo
```

[Download the Reprint client](../requirements/) into this folder and keep
its filename as `reprint.phar`. Check that it runs:

```bash
php reprint.phar --version
```

You should see a Reprint version number. If the command fails, follow
[Troubleshooting](../troubleshooting/) before starting a copy.

## 2. Install the plugin on the source site

In the source site's WordPress admin, open **Plugins → Add New Plugin**.
Search for **Reprint**, install it, and activate it. Then open
**Tools → Reprint Server**.

Leave that screen open. The next step gives you the Migration Token to paste
there. See [Authorize a tool](../authorize-a-tool/) for the full access steps.

## 3. Start the copy on your computer

In the same terminal folder, run this command. Replace `https://example.com`
with the source site's HTTPS address:

```bash
php reprint.phar pull https://example.com \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite --new-site-url=http://localhost:8881
```

The first run generates a private key, prints its public Migration Token,
and stops with exit code `4`. This is the expected authorization step.

1. Copy the printed Migration Token. Do not copy the private key file.
2. Paste it into **Tools → Reprint Server** on the source site.
3. Choose **Authorize tool**.
4. Run the same terminal command again.

If the plugin instead shows a **Connection token** form, use the alternative
steps in [Authorize a tool](../authorize-a-tool/), then add `--secret='TOKEN'`
to the pull command using that connection token.

The client now checks the source, downloads files and database data, imports
the database into SQLite, rewrites the site URL, and prepares a local server.
Large sites take longer. Keep the terminal open.

## 4. Open and check the copy

When Reprint starts the local server, open the address it prints. For this
tutorial it is `http://localhost:8881`.

Before testing features that send email, take payments, or call other live
services, disable those connections or replace them with test settings.

Check these on the local address:

1. The home page opens.
2. A few internal links stay on the local site.
3. Images load.
4. You can sign in at `/wp-admin/` with an account from the source site.

Some plugins need MySQL or services provided by the source host. A completed
transfer does not establish that every plugin works locally. If a check fails,
see [Troubleshooting](../troubleshooting/).

## 5. Stop and start the local server

Press `Ctrl+C` in the terminal to stop serving the site. Your copy stays on
disk. From the `reprint-demo` folder, start it again with:

```bash
bash state/runtime/start.sh
```

Keep this working folder in place, including `state/` and `files/`.
`state/` contains credentials, transfer progress, the SQL download, and
generated server files.
`files/` contains the downloaded site tree, including its local SQLite database.
Keep both private.

To resume an interrupted transfer, run the same `pull` command again with the
same source address and folders. To only restart the local server, use
`start.sh` instead. Running `pull` after completion can replace local database
changes with source data.

Next: [How Reprint works](../how-it-works/), or
[remove client access](../authorize-a-tool/) when you no longer need it.
