# Try Reprint

Copy the Reprint demo site to your computer and open the copy in your browser.
You do not need your own WordPress site or a WordPress login. The demo provides
a shared access key for the client.

You need a terminal and PHP with SQLite support. Check
[Requirements and downloads](https://developer.wordpress.org/reprint/requirements/)
if PHP is not installed or a required extension is missing.

## 1. Download the client and demo access key

Create a working folder:

```sh
mkdir try-reprint
cd try-reprint
```

Download Reprint and the demo's shared key into that folder:

```sh
curl -fL https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar -o reprint.phar
curl -fL https://reprint-demo.wordpress.net/public.key -o demo.key
php reprint.phar --version
```

The last command should print a Reprint version number. If a download or PHP
command fails, resolve that error before continuing.

## 2. Copy the demo

In the same folder, run:

```sh
php reprint.phar pull https://reprint-demo.wordpress.net/ \
  --private-key-path=./demo.key \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite --new-site-url=http://localhost:8881
```

The client uses the downloaded key to access the demo, downloads its files and
database, and starts your local copy. There is no WordPress authorization step
for this demo. Keep the terminal open while it runs.

## 3. Open your copy

Open http://localhost:8881. You should see **Reprint Field Notes** with its
journal posts and photographs. Open a story and check that its address still
starts with http://localhost:8881; you are browsing your local copy.

Your working folder now contains the copied site in `files/` and the client's
credentials, saved progress, and server files in `state/`. Keep both directories.
Changes to your local copy do not change the demo site.

## 4. Stop and reopen the copy

Press `Ctrl+C` in the terminal to stop the local server. From `try-reprint`,
start it again without downloading the site again:

```sh
bash state/runtime/start.sh
```

If the transfer stops before the copy is ready, run the same `pull` command
again with the same folders and key. To reopen a finished copy, use the start
script instead; another `pull` can replace local database changes.

Next, [Clone your site](https://developer.wordpress.org/reprint/copy-a-site/)
or [run Reprint from your product](https://developer.wordpress.org/reprint/build-with-reprint/).
