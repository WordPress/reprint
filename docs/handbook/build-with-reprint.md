# Build with Reprint

Your product can run the Reprint client to prepare a WordPress copy. This
example runs one copy, prints stage messages, and reports whether the copy
completed or needs user action. It uses PHP, with no extra libraries.

## Prepare the source and working folder

Download [reprint.phar](requirements.md) into a private working folder.
Install the [Reprint plugin](https://wordpress.org/plugins/reprint/) on the
source site. This example uses the normal Migration Token authorization shown
in the plugin settings; [Authentication](authentication.md) covers token-only hosts.

Generate a key in the working folder:

```sh
php reprint.phar keygen https://example.com \
  --state-dir=./state --out=./reprint-key.pem
```

Paste the printed Migration Token into **Tools → Reprint Server** on the source
site and choose **Authorize tool**. Use that same source address below.
For connection-token access, replace the private-key argument in the script
with `'--secret=TOKEN'`, using the saved connection token.

## Authorize from your product

A product usually cannot ask its user to run `keygen` in a terminal. Two
options keep the authorization step inside your interface:

- Run `keygen` yourself with `--progress=jsonl`. Its final `reprint_report`
  record carries `key_id`, `key_path`, and `public_key`, and its `message` holds
  the same instructions the terminal would print. Show the Migration Token, the
  `public_key` value, to the site administrator with a link to their site's
  **Tools → Reprint Server** page. When they confirm, start the copy with
  `--private-key-path` pointing at `key_path`.
- Let `pull` do it. Run the copy command without `--private-key-path`. On the
  first run the client generates a key inside the state directory, writes a
  report with `status: "enrollment_needed"` and the same key fields, and exits
  with code `4`. Show its `message` to the administrator. After they authorize
  the token, run the same command again; later runs find the key in the state
  directory as long as that directory is kept.

Treat exit code `4` as a request for user action, not as a failure to retry
automatically. If the administrator removes the token later, the client reports
`AUTH_UNKNOWN_KEY`. The same Migration Token can be enrolled again;
`pull-metadata` prints it as `key.publicKey`.

## Run a copy from your application

Save this as `copy-site.php` beside `reprint.phar`:

```php
<?php
if (!isset($argv[1])) {
    fwrite(STDERR, "Usage: php copy-site.php https://example.com\n");
    exit(1);
}

$process = proc_open(
    [PHP_BINARY, __DIR__ . '/reprint.phar', 'pull', $argv[1],
        '--private-key-path=' . __DIR__ . '/reprint-key.pem',
        '--state-dir=' . __DIR__ . '/state', '--fs-root=' . __DIR__ . '/files',
        '--target-engine=sqlite', '--new-site-url=http://localhost:8881',
        '--start-runtime=none', '--progress=compact'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
    $pipes,
    __DIR__
);
if ($process === false) {
    fwrite(STDERR, "Could not start the client.\n");
    exit(1);
}
fclose($pipes[0]);

$report = null;
while (($line = fgets($pipes[1])) !== false) {
    $record = json_decode($line, true);
    if (!is_array($record)) {
        continue;
    }
    if (($record['type'] ?? null) === 'reprint_report') {
        if (($record['schema_version'] ?? null) === 1 && ($record['command'] ?? null) === 'pull') {
            $report = $record;
        }
    } elseif (isset($record['message'])) {
        echo $record['message'], "\n";
    }
}
fclose($pipes[1]);
$exit_code = proc_close($process);

if ($report === null) {
    fwrite(STDERR, "No supported final report. Check the client output.\n");
    exit(1);
}
if ($exit_code === 0 && $report['status'] === 'complete') {
    echo "Copy prepared. Run: bash state/runtime/start.sh\n";
} elseif ($exit_code === 2) {
    echo "Work is unfinished. Run this script again to continue.\n";
} elseif ($exit_code === 3) {
    fwrite(STDERR, "Temporary failure. Run this script again later.\n");
} else {
    fwrite(STDERR, ($report['error'] ?? 'The copy did not complete.') . "\n");
    $exit_code = $exit_code === 0 ? 1 : $exit_code;
}
exit($exit_code);
```

Run it with your source address:

```sh
php copy-site.php https://example.com
```

During the copy, the script prints stage messages. After it reports
**Copy prepared**, you can open the local result with:

```sh
bash state/runtime/start.sh
```

`--start-runtime=none` keeps the client from starting a server while the
integration job runs; see [runtime options on pull](runtime.md#runtime-options-on-pull).
The generated start script is only for local development.

## Handle the next action

| Result | What your product does |
| --- | --- |
| Copy prepared | Offer the prepared copy to your product's next step. |
| Unfinished work | Run again with the same folder and source address. |
| Temporary failure | Let the caller decide when to try again. |
| Failure or missing final report | Show the error and stop; do not report a successful copy. |

Keep each copy's working folder, including `reprint-key.pem`, `state/`, and
`files/`, between runs.
State contains credentials and transfer progress; keep it private and outside
web-served directories. Use a separate folder for a different local copy.
The script checks both the process result and the final command report rather
than treating the last progress message as success.

## Go further when needed

- [Progress, results, and exit codes](progress-and-results.md) documents the full
  output contract, including numeric counters for your UI.
- [Copy selected files or the database](copy-selected-data.md) covers partial copies.
- [Control each migration step](integration-pipeline.md) is for products that
  need separate indexing, transfer, and import stages.
- [Prepare a hosting destination](hosting.md) covers production-server setup
  and cleanup. This example deliberately uses a local SQLite destination.

For a first manual copy, use [Clone your site](copy-a-site.md).
For PHP embedding and source contributions, use the
[development repository](https://github.com/WordPress/reprint).
