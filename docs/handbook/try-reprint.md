# Try Reprint

Copy the Reprint demo site to your computer and open the copy in your browser. You do not need a WordPress site, an account, or a key.

You need a terminal and PHP with SQLite support. Check [Requirements and downloads](https://developer.wordpress.org/reprint/getting-started/requirements/) if PHP is not installed or a required extension is missing.

## 1. Download the client

Create a working folder:

```sh
mkdir try-reprint
cd try-reprint
```

Download Reprint into that folder:

```sh
curl -fL https://github.com/WordPress/reprint/releases/latest/download/reprint.phar -o reprint.phar
php reprint.phar --version
```

The last command should print a Reprint version number. If a download or PHP command fails, resolve that error before continuing.

## 2. Copy the demo

In the same folder, run:

```sh
php reprint.phar pull https://reprint-demo.wordpress.net \
  --state-dir=./state --fs-root=./files \
  --secret=public-demo --new-site-url=http://localhost:8881
```

`public-demo` is a placeholder for the CLI. The demo server ignores it. The `--new-site-url` option rewrites links to the demo so they stay on your local copy. Reprint downloads the files and database, then starts your local copy. Keep the terminal open while it runs.

## 3. Open your copy

Open http://localhost:8881. You should see **Reprint Field Notes** with its journal posts and photographs. Open a story and check that its address still starts with http://localhost:8881; you are browsing your local copy.

Your working folder now contains the copied site in `files/` and the client's saved progress and server files in `state/`. Keep both directories. Changes to your local copy do not change the demo site.

## 4. Stop and reopen the copy

Press `Ctrl+C` in the terminal to stop the local server. From `try-reprint`, start it again without downloading the site again:

```sh
bash state/runtime/start.sh
```

If the transfer stops before the copy is ready, run the same `pull` command again with the same folders. To reopen a finished copy, use the start script instead; another `pull` can replace local database changes.

Next, [Clone your site](https://developer.wordpress.org/reprint/getting-started/copy-a-site/) or [run Reprint from your product](https://developer.wordpress.org/reprint/build-with-reprint/).
