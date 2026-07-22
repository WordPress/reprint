<?php
/**
 * Reprint Web Wizard — WordPress.com → Playground handoff.
 *
 * 1. Sign in with WordPress.com (OAuth authorization-code flow).
 * 2. List the user's Atomic/WP.com sites.
 * 3. Server enables the reprint exporter + rotates the HMAC secret
 *    via the WP.com REST API and returns {api_url, secret}.
 * 4. Browser navigates to a Playground URL with ?blueprint-url
 *    pointing back at this wizard's `?action=blueprint` endpoint.
 *    The api_url + secret are passed in the URL hash, so they only
 *    live in the user's browser — never in this server's logs.
 * 5. The Blueprint installs reprint.phar + a /wordpress/reprint-import.php
 *    page inside Playground. Playground lands on that page, which
 *    reads the credentials from the hash and runs the import in place.
 */

require __DIR__ . '/bootstrap.php';

reprint_session_start();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':       action_login(); exit;
    case 'logout':      action_logout(); exit;
    case 'sites':       action_sites(); exit;
    case 'me':          action_me(); exit;
    case 'provision':   action_provision(); exit;
    case 'status':      action_status(); exit;
    case 'blueprint':   action_blueprint(); exit;
}

render_wizard();

// ──────────────────────────────────────────────────────────────────────────
// OAuth + WP.com site listing
// ──────────────────────────────────────────────────────────────────────────

function action_login(): void {
    [$client_id, ] = reprint_client_credentials();
    if ($client_id === '') { http_response_code(500); echo 'WPCOM_CLIENT_ID not configured.'; return; }
    $state = bin2hex(random_bytes(16));
    // 10-minute TTL — long enough to complete the WP.com authorize
    // round-trip but bounded so a leaked nonce can't be reused later.
    reprint_cookie_set('rp_state', ['s' => $state], 600);
    $params = [
        'client_id' => $client_id,
        'redirect_uri' => reprint_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'global',
        'state' => $state,
    ];
    header('Location: https://public-api.wordpress.com/oauth2/authorize?' . http_build_query($params));
}

function action_logout(): void {
    reprint_cookie_clear('rp_tok');
    reprint_cookie_clear('rp_state');
    header('Location: /reprint.php');
}

function action_sites(): void {
    header('Content-Type: application/json');
    $token = reprint_wpcom_token();
    if ($token === '') { http_response_code(401); echo json_encode(['error' => 'not_authenticated']); return; }
    $sites = wpcom_get([
        'url' => 'https://public-api.wordpress.com/rest/v1.1/me/sites?filter=atomic,wpcom,jetpack&fields=ID,URL,name,icon,is_wpcom_atomic',
        'token' => $token,
    ]);
    echo $sites;
}

function action_me(): void {
    header('Content-Type: application/json');
    $token = reprint_wpcom_token();
    if ($token === '') { http_response_code(401); echo json_encode(['error' => 'not_authenticated']); return; }
    echo wpcom_get([
        'url' => 'https://public-api.wordpress.com/rest/v1.1/me?fields=display_name,username,avatar_URL,email',
        'token' => $token,
    ]);
}

/** GET the given WP.com URL with a bearer token. Returns raw JSON string. */
function wpcom_get(array $opts): string {
    $ch = curl_init($opts['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $opts['token'],
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return json_encode(['error' => 'network']);
    if ($status >= 400) { http_response_code($status); }
    return (string) $body;
}

/**
 * $encoding: 'json' for endpoints that expect a JSON body (e.g. the
 * Jetpack REST bridge at /jetpack-blogs/<id>/rest-api), 'form' for the
 * classic WP.com REST endpoints (e.g. /sites/<id>/settings) — wpcom.js
 * defaults to form-encoded and those endpoints silently ignore JSON
 * bodies, dropping unknown keys with a 200 OK.
 */
function wpcom_post(string $url, string $token, array $body, string $encoding = 'json'): array {
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if ($encoding === 'form') {
        $payload = http_build_query($body);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } else {
        $payload = json_encode($body);
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $resp, 'json' => json_decode((string) $resp, true)];
}

/**
 * Enables the reprint exporter on the chosen site (sliding 60-min window),
 * then rotates the HMAC secret. Returns [api_url, secret, debug].
 *
 * Mirrors Studio's pull-reprint flow: enable → rotate → use.
 *
 * Two export surfaces coexist while sites migrate (STU-1929):
 *  - v2: the Jetpack-hosted `jetpack/v4/reprint/*` routes + `?reprint-api-v2`,
 *    available on Pressable and current Atomic sites.
 *  - v1: the wpcomsh `/sites/<id>/settings` enable + `wpcomsh/v1/reprint/*`
 *    rotate + `?reprint-api`, on Atomic sites running older Jetpack.
 * Pressable has only v2, older WoA only v1, current WoA both — so we probe for
 * v2 first and fall back to v1 rather than guessing from the site's platform.
 *
 * `$site_url` is passed by the client from the already-loaded site list
 * — avoids an extra `/sites/<id>?fields=URL` round-trip that the
 * current token scope doesn't always cover.
 */
function wpcom_provision_reprint(int $site_id, string $site_url, string $token): array {
    // Probe for v2. The `jetpack/v4/reprint/*` routes are only registered on
    // hosts where the exporter is available, so enable-export 404s when the site
    // has no v2 support — that 404 is our capability probe. Unlike rotate it
    // mints no secret, so calling it speculatively is harmless; on v2 it also
    // opens the 60-minute export window.
    $enable = wpcom_post(
        "https://public-api.wordpress.com/rest/v1.1/jetpack-blogs/{$site_id}/rest-api?http_envelope=1",
        $token,
        ['path' => '/jetpack/v4/reprint/enable-export']
    );
    $enable_status = wpcom_bridge_status($enable);

    if ($enable_status === 200) {
        return wpcom_provision_reprint_v2($site_id, $site_url, $token, $enable);
    }
    if ($enable_status === 404) {
        return wpcom_provision_reprint_v1($site_id, $site_url, $token);
    }
    throw new RuntimeException(
        "enable-export probe failed (HTTP {$enable_status}): " . substr((string) $enable['body'], 0, 300)
    );
}

/**
 * Effective inner HTTP status of a Jetpack REST-bridge response.
 *
 * The bridge is called with http_envelope=1, so the outer HTTP is always 200
 * and the proxied site's status lives in the envelope's `code`. A site missing
 * the route answers `rest_no_route`; normalise that to 404 so route probing
 * stays reliable regardless of how the bridge surfaces a missing route.
 */
function wpcom_bridge_status(array $response): int {
    $env = $response['json'] ?? null;
    if (!is_array($env)) {
        return (int) ($response['status'] ?? 0);
    }
    $inner = $env['body'] ?? null;
    if (is_string($inner)) {
        $inner = json_decode($inner, true);
    }
    if (is_array($inner) && ($inner['code'] ?? null) === 'rest_no_route') {
        return 404;
    }
    return (int) ($env['code'] ?? 0);
}

/**
 * v2 provisioning: rotate the secret via the `jetpack/v4` bridge route and use
 * the `?reprint-api-v2` endpoint. The window is already open from the probe's
 * enable-export call, and rotate opens it again — belt and suspenders.
 */
function wpcom_provision_reprint_v2(int $site_id, string $site_url, string $token, array $enable): array {
    $rotated = wpcom_post(
        "https://public-api.wordpress.com/rest/v1.1/jetpack-blogs/{$site_id}/rest-api?http_envelope=1",
        $token,
        ['path' => '/jetpack/v4/reprint/rotate-export-secret']
    );
    $env = $rotated['json'] ?? null;
    if (!is_array($env) || ($env['code'] ?? 0) !== 200) {
        throw new RuntimeException('rotate-export-secret failed: ' . substr((string)$rotated['body'], 0, 300));
    }
    // Jetpack bridge returns the inner response in `body`, sometimes as an
    // object, sometimes JSON-encoded — normalise both shapes.
    $inner = $env['body'] ?? null;
    if (is_string($inner)) {
        $inner = json_decode($inner, true);
    }
    $secret = is_array($inner) ? ($inner['secret'] ?? null) : null;
    if (!$secret) {
        throw new RuntimeException('No secret in rotate response: ' . substr((string)$rotated['body'], 0, 400));
    }

    $debug = [
        'surface'    => 'v2',
        'enable_raw' => (string) $enable['body'],
        'rotate_raw' => (string) $rotated['body'],
    ];
    return [rtrim($site_url, '/') . '/?reprint-api-v2', $secret, $debug];
}

/**
 * v1 provisioning (Atomic sites on older Jetpack): enable via the wpcomsh
 * settings whitelist, rotate via the `wpcomsh/v1` bridge route, and use the
 * `?reprint-api` endpoint.
 */
function wpcom_provision_reprint_v1(int $site_id, string $site_url, string $token): array {
    // Step 1: set reprint_exporter_enabled=<timestamp> via /sites/<id>/settings.
    // WP.com's settings endpoint whitelists keys and silently drops unknown ones
    // with a 200 OK, so we verify the key actually appears in `updated` —
    // matching Studio's enableReprintExporter behavior.
    $enabled = wpcom_post(
        "https://public-api.wordpress.com/rest/v1.1/sites/{$site_id}/settings",
        $token,
        ['reprint_exporter_enabled' => time()],
        'form'
    );
    if ($enabled['status'] >= 400) {
        throw new RuntimeException("enable-export-api failed (HTTP {$enabled['status']}): " . substr((string)$enabled['body'], 0, 300));
    }
    $updated = $enabled['json']['updated'] ?? null;
    if (!is_array($updated) || !array_key_exists('reprint_exporter_enabled', $updated)) {
        throw new RuntimeException(
            'The site did not acknowledge the reprint exporter activation. ' .
            'The feature may not be available yet on this WordPress.com site. ' .
            'Response: ' . substr((string) $enabled['body'], 0, 400)
        );
    }

    // Step 2: rotate the HMAC secret via the Jetpack bridge.
    $rotated = wpcom_post(
        "https://public-api.wordpress.com/rest/v1.1/jetpack-blogs/{$site_id}/rest-api?http_envelope=1",
        $token,
        ['path' => '/wpcomsh/v1/reprint/rotate-export-secret']
    );
    $env = $rotated['json'] ?? null;
    if (!is_array($env) || ($env['code'] ?? 0) !== 200) {
        throw new RuntimeException('rotate-export-secret failed: ' . substr((string)$rotated['body'], 0, 300));
    }
    // Jetpack bridge returns the inner response in `body`. WP.com sometimes
    // hands it back as an object, sometimes as a JSON-encoded string —
    // normalise both shapes.
    $inner = $env['body'] ?? null;
    if (is_string($inner)) {
        $inner = json_decode($inner, true);
    }
    $secret = is_array($inner) ? ($inner['data']['secret'] ?? null) : null;
    if (!$secret) {
        throw new RuntimeException('No secret in rotate response: ' . substr((string)$rotated['body'], 0, 400));
    }

    // Belt-and-suspenders: if the site also has the legacy
    // `reprint-exporter-wp` standalone plugin installed, that plugin
    // intercepts ?reprint-api at plugin-load time and reads its secret
    // from the `site_export_secret` option (NOT wpcomsh's
    // `reprint_exporter_secret`). Writing the rotated value to both
    // options means whichever handler fires first will accept us.
    // The settings endpoint silently drops keys it doesn't whitelist,
    // so this is a no-op on sites without the legacy plugin.
    $legacy_sync = wpcom_post(
        "https://public-api.wordpress.com/rest/v1.1/sites/{$site_id}/settings",
        $token,
        ['site_export_secret' => $secret],
        'form'
    );

    $debug = [
        'surface'             => 'v1',
        'enable_updated'      => $updated,
        'rotate_raw'          => (string) $rotated['body'],
        'legacy_sync_status'  => $legacy_sync['status'],
        'legacy_sync_updated' => $legacy_sync['json']['updated'] ?? null,
    ];
    return [rtrim($site_url, '/') . '/?reprint-api', $secret, $debug];
}

// ──────────────────────────────────────────────────────────────────────────
// Import orchestration
// ──────────────────────────────────────────────────────────────────────────

/**
 * Server-side provisioning: enables the reprint exporter on the chosen
 * WP.com site and rotates the HMAC secret. Returns JSON with the
 * `api_url` (the site's ?reprint-api / ?reprint-api-v2 endpoint) and `secret` so the browser
 * can hand them to a Playground iframe that runs the actual import.
 *
 * No phar invocation, no streaming, no artifact staging — that all
 * happens client-side now, inside Playground, where browser-class
 * User-Agent and direct routing avoid the WAF/UA rejections we hit
 * when calling from this server.
 */
function action_provision(): void {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $token = reprint_wpcom_token();
    if ($token === '') {
        http_response_code(401);
        echo json_encode(['error' => 'not_authenticated']);
        return;
    }

    $site_id = (int) ($_POST['site_id'] ?? 0);
    $site_url = (string) ($_POST['site_url'] ?? '');
    if ($site_id <= 0 || $site_url === '' || !preg_match('~^https?://[^\s]+$~i', $site_url)) {
        http_response_code(400);
        echo json_encode(['error' => 'site_id and a valid site_url are required']);
        return;
    }

    try {
        [$api_url, $secret, ] = wpcom_provision_reprint($site_id, $site_url, $token);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => 'provision_failed', 'message' => $e->getMessage()]);
        return;
    }

    echo json_encode([
        'api_url' => $api_url,
        'secret' => $secret,
        'site_url' => $site_url,
        'site_id' => $site_id,
    ]);
}

function action_status(): void {
    header('Content-Type: application/json');
    echo json_encode([
        'authenticated' => reprint_wpcom_token() !== '',
        'client_id' => reprint_client_credentials()[0],
    ]);
}

/**
 * Returns a WordPress Playground Blueprint that:
 *   1. Boots a fresh WordPress in /wordpress.
 *   2. Drops reprint.phar into the VFS.
 *   3. Drops a preload file that pre-defines DB_NAME / ABSPATH /
 *      WP_CONTENT_DIR so Atomic-style wp-configs (which rely on the
 *      host injecting DB credentials) can boot inside Playground.
 *   4. Drops the importer page at /wordpress/reprint-import.php.
 *   5. Sets the landing page to /reprint-import.php — so when the
 *      browser opens this Blueprint URL, it lands directly on the
 *      importer UI, which then reads api_url + secret from the URL
 *      fragment and runs the clone end-to-end.
 *
 * The api_url + secret never enter this endpoint — Playground fetches
 * the Blueprint server-side, then loads the iframe, then JS in the
 * iframe reads window.location.hash to pick up the credentials.
 */
function action_blueprint(): void {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    // Allow Playground to fetch this blueprint cross-origin.
    header('Access-Control-Allow-Origin: *');

    $origin = reprint_site_origin();

    // Credentials arrive as query params on this endpoint URL. URL
    // fragments would be safer (browser-only) but they don't survive
    // the parent→iframe transition that Playground uses to load the
    // landing page. Both this server and playground.wordpress.net
    // are operated by Automattic, so the leak surface is bounded;
    // the secret is a 60-minute rotating HMAC for one site anyway.
    $api    = (string) ($_GET['api'] ?? '');
    $secret = (string) ($_GET['secret'] ?? '');
    $source = (string) ($_GET['source'] ?? '');

    // The importer page is shipped as a sibling file in this directory.
    // We inline its source verbatim and prepend a constants block that
    // bakes in the credentials, so the Blueprint is fully self-describing
    // (one URL, no secondary fetches besides the phar).
    $importer_php = (string) @file_get_contents(__DIR__ . '/reprint-import.php');
    if ($importer_php === '') {
        http_response_code(500);
        echo json_encode(['error' => 'reprint-import.php missing on the wizard server']);
        return;
    }
    if ($api !== '' && $secret !== '') {
        // Important: don't emit a closing PHP tag here. The importer
        // file's own opening tag is stripped below, so we need to STAY
        // in PHP mode through the stitched result — otherwise the rest
        // of the file (docblock, function defs) leaks out as HTML.
        $consts = "<?php\n"
            . "// Injected by /reprint.php?action=blueprint at fetch time.\n"
            . "if (!defined('REPRINT_API_URL'))       define('REPRINT_API_URL', "       . var_export($api, true)    . ");\n"
            . "if (!defined('REPRINT_SECRET'))        define('REPRINT_SECRET', "        . var_export($secret, true) . ");\n"
            . "if (!defined('REPRINT_SOURCE_ORIGIN')) define('REPRINT_SOURCE_ORIGIN', " . var_export($source, true) . ");\n"
            . "\n";
        // Strip the importer's own opening tag so we can stitch it
        // after our consts block. preg_replace with limit=1 keeps this
        // tolerant if the file ever starts with whitespace or a UTF-8 BOM.
        $importer_php = preg_replace('/^<\?php\s*/', '', $importer_php, 1);
        $importer_php = $consts . $importer_php;
    }

    // Pre-define DB and path constants so Atomic / wpcomstaging
    // wp-config.php files (which expect host-injected DB creds) can
    // boot inside Playground. Auto-prepended before wp-config runs.
    //
    // The DB_* defines need a guard that PHP's `if (!defined())`
    // alone can't provide: Playground's stock wp-config-sample.php
    // defines DB_NAME / DB_USER / DB_PASSWORD / DB_HOST / DB_CHARSET /
    // DB_COLLATE *unconditionally* (that's what wp-config-sample looks
    // like out of the box). If we predefine them and that wp-config
    // is still in place, every define() in wp-config raises a
    // "Constant ... already defined" warning that gets emitted to the
    // browser before headers — and Playground's redirect handler
    // chokes on the leading text. So gate the DB_* defines on whether
    // the live /wordpress/wp-config.php still has its own define()s
    // for them. Atomic / wpcomstaging wp-configs (the ones that need
    // our help) don't — Atomic's host injects DB creds at runtime —
    // so once the importer rewrites wp-config we step in. While the
    // stock Playground wp-config is still there, we stay out of the
    // way and let it set DB_*. Path constants (ABSPATH, WP_CONTENT_DIR,
    // etc.) are already guarded inside wp-config so they're safe.
    $preload_php = "<?php\n"
        . "// Reprint preload — pre-empts Atomic wp-config defines.\n"
        . "if (!defined('ABSPATH'))         define('ABSPATH', '/wordpress/');\n"
        . "if (!defined('WP_CONTENT_DIR'))  define('WP_CONTENT_DIR', '/wordpress/wp-content');\n"
        . "if (!defined('WP_PLUGIN_DIR'))   define('WP_PLUGIN_DIR', '/wordpress/wp-content/plugins');\n"
        . "if (!defined('WPMU_PLUGIN_DIR')) define('WPMU_PLUGIN_DIR', '/wordpress/wp-content/mu-plugins');\n"
        . "if (!defined('WP_LANG_DIR'))     define('WP_LANG_DIR', '/wordpress/wp-content/languages');\n"
        . "if (!defined('WP_TEMP_DIR'))     define('WP_TEMP_DIR', '/tmp');\n"
        . "// Atomic wp-configs assume the host injects DB_*; without\n"
        . "// these the SQLite drop-in bails with empty DB_NAME and\n"
        . "// \$wpdb->dbh stays null — straight to the install wizard\n"
        . "// despite a populated DB. But we must NOT predefine these\n"
        . "// while Playground's stock wp-config-sample.php is still\n"
        . "// in place — its unconditional define() calls would then\n"
        . "// warn-spam the browser before headers. So skip if the live\n"
        . "// wp-config.php still defines them itself.\n"
        . "if (is_file('/wordpress/wp-config.php')\n"
        . "    && strpos(@file_get_contents('/wordpress/wp-config.php'), \"define( 'DB_NAME'\") === false\n"
        . "    && strpos(@file_get_contents('/wordpress/wp-config.php'), \"define('DB_NAME'\") === false) {\n"
        . "    if (!defined('DB_NAME'))         define('DB_NAME', 'wordpress');\n"
        . "    if (!defined('DB_USER'))         define('DB_USER', 'wordpress');\n"
        . "    if (!defined('DB_PASSWORD'))     define('DB_PASSWORD', 'wordpress');\n"
        . "    if (!defined('DB_HOST'))         define('DB_HOST', 'localhost');\n"
        . "    if (!defined('DB_CHARSET'))      define('DB_CHARSET', 'utf8mb4');\n"
        . "    if (!defined('DB_COLLATE'))      define('DB_COLLATE', '');\n"
        . "}\n";

    $blueprint = [
        'landingPage' => '/reprint-import.php',
        'preferredVersions' => [
            'php' => '8.2',
            'wp' => 'latest',
        ],
        'phpExtensionBundles' => ['light'],
        'features' => [
            'networking' => true,
        ],
        'steps' => [
            [
                'step' => 'writeFile',
                'path' => '/wordpress/reprint.phar',
                'data' => [
                    'resource' => 'url',
                    'url' => $origin . '/reprint.phar',
                ],
            ],
            [
                'step' => 'writeFile',
                'path' => '/wordpress/reprint-import.php',
                'data' => [
                    'resource' => 'literal',
                    'name' => 'reprint-import.php',
                    'contents' => $importer_php,
                ],
            ],
            [
                'step' => 'writeFile',
                'path' => '/internal/shared/preload/01-reprint-paths.php',
                'data' => [
                    'resource' => 'literal',
                    'name' => '01-reprint-paths.php',
                    'contents' => $preload_php,
                ],
            ],
        ],
    ];

    echo json_encode($blueprint, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ──────────────────────────────────────────────────────────────────────────
// UI
// ──────────────────────────────────────────────────────────────────────────

function render_wizard(): void {
    $authed = reprint_wpcom_token() !== '';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reprint · WordPress.com → Playground</title>
<style>
  :root {
    --bg:#0b0d12; --panel:#12151c; --panel-2:#181c26; --border:#242936;
    --fg:#e7ecf3; --muted:#8a94a7; --accent:#7c5cff; --accent-2:#4ad1c2;
    --ok:#3ecf8e; --err:#ff6b6b;
    --mono:ui-monospace,SFMono-Regular,Menlo,monospace;
  }
  *{box-sizing:border-box}
  html,body{margin:0;background:var(--bg);color:var(--fg);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif}
  body{min-height:100vh;background:
    radial-gradient(800px 400px at 10% -10%, rgba(124,92,255,.18), transparent 60%),
    radial-gradient(600px 400px at 90% 0%, rgba(74,209,194,.12), transparent 60%),
    var(--bg);}
  .shell{max-width:880px;margin:0 auto;padding:48px 24px 96px}
  header.brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}
  .logo{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent-2));box-shadow:0 4px 24px rgba(124,92,255,.35)}
  h1{font-size:22px;font-weight:600;letter-spacing:-0.01em;margin:0}
  .subtitle{color:var(--muted);font-size:14px;margin-top:2px}
  .stepper{display:flex;gap:8px;margin:24px 0 20px}
  .step{flex:1;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--panel);font-size:13px;color:var(--muted);display:flex;align-items:center;gap:10px}
  .step .num{width:22px;height:22px;border-radius:50%;background:var(--panel-2);display:grid;place-items:center;font-size:12px;color:var(--muted)}
  .step.active{border-color:var(--accent);color:var(--fg)}
  .step.active .num{background:var(--accent);color:#fff}
  .step.done .num{background:var(--ok);color:#0b0d12}
  .step.done{color:var(--fg)}
  .card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:28px;box-shadow:0 1px 0 rgba(255,255,255,.02) inset, 0 12px 32px rgba(0,0,0,.35)}
  .card h2{margin:0 0 6px;font-size:17px}
  .card p.desc{margin:0 0 20px;color:var(--muted);font-size:14px;line-height:1.5}
  button.primary{background:linear-gradient(135deg,var(--accent),#5a3fe0);color:#fff;border:0;padding:11px 18px;border-radius:9px;font-size:14px;font-weight:500;cursor:pointer;box-shadow:0 6px 18px rgba(124,92,255,.35);transition:transform .06s,box-shadow .15s}
  button.primary:hover{box-shadow:0 10px 24px rgba(124,92,255,.45)}
  button.primary:active{transform:translateY(1px)}
  button.primary:disabled{opacity:.5;cursor:not-allowed;box-shadow:none}
  button.ghost{background:transparent;color:var(--muted);border:1px solid var(--border);padding:10px 14px;border-radius:9px;cursor:pointer;font-size:13px}
  button.ghost:hover{color:var(--fg);border-color:var(--accent)}
  .actions{display:flex;gap:10px;align-items:center;margin-top:22px}
  .site-list{display:flex;flex-direction:column;gap:10px;margin-top:12px;max-height:380px;overflow-y:auto;padding-right:4px}
  input[type="search"]{width:100%;padding:11px 13px;font-size:14px;background:var(--panel-2);color:var(--fg);border:1px solid var(--border);border-radius:9px;font-family:inherit;transition:border-color .15s,box-shadow .15s}
  input[type="search"]:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,92,255,.22)}
  .site{display:grid;grid-template-columns:40px 1fr auto;gap:14px;align-items:center;padding:12px 14px;background:var(--panel-2);border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color .1s}
  .site:hover{border-color:var(--accent)}
  .site.selected{border-color:var(--accent);box-shadow:0 0 0 2px rgba(124,92,255,.2)}
  .site.disabled{opacity:.45;cursor:not-allowed;filter:grayscale(.6)}
  .site.disabled:hover{border-color:var(--border);box-shadow:none}
  .site .reason{font-size:11px;color:var(--muted);margin-top:3px;font-style:italic}
  .user-pill img.avatar{width:22px;height:22px;border-radius:50%;object-fit:cover}
  .site img,.site .site-icon{width:40px;height:40px;border-radius:8px;background:var(--panel);object-fit:cover;display:grid;place-items:center;color:var(--muted);font-size:14px;font-weight:600}
  .site .site-title{font-size:14px;font-weight:500}
  .site .site-url{font-size:12px;color:var(--muted);font-family:var(--mono);margin-top:2px}
  .badge{font-size:11px;padding:3px 8px;border-radius:999px;background:var(--panel);color:var(--muted);border:1px solid var(--border)}
  .progress-list{display:flex;flex-direction:column;gap:10px;margin-top:6px}
  .phase{display:grid;grid-template-columns:28px 1fr auto;gap:12px;align-items:center;padding:12px 14px;background:var(--panel-2);border:1px solid var(--border);border-radius:10px}
  .phase .dot{width:10px;height:10px;border-radius:50%;background:#35394a;justify-self:center}
  .phase.active .dot{background:var(--accent);box-shadow:0 0 12px var(--accent);animation:pulse 1.4s infinite}
  .phase.done .dot{background:var(--ok)}
  .phase.error .dot{background:var(--err)}
  .phase .name{font-size:14px}
  .phase .meta{font-size:12px;color:var(--muted);font-family:var(--mono)}
  @keyframes pulse{50%{opacity:.4}}
  @keyframes spin{to{transform:rotate(360deg)}}
  #site-refresh{display:inline-flex;align-items:center;color:var(--accent-2);opacity:.85}
  .bar{height:6px;background:var(--panel-2);border-radius:999px;overflow:hidden;margin-top:14px}
  .bar > div{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent-2));width:0%;transition:width .25s}
  .log{margin-top:20px;padding:14px;background:#07080c;border:1px solid var(--border);border-radius:10px;font-family:var(--mono);font-size:12px;color:#c7cddb;max-height:260px;overflow:auto;white-space:pre-wrap}
  .log .err{color:var(--err)}
  .log .info{color:var(--accent-2)}
  .hidden{display:none!important}
  .playground-frame{width:100%;height:640px;border:1px solid var(--border);border-radius:10px;background:#000;margin-top:14px}
  .user-pill{display:flex;align-items:center;gap:8px;margin-left:auto;font-size:12px;color:var(--muted)}
  .user-pill a{color:var(--accent-2);text-decoration:none}
  footer{text-align:center;color:var(--muted);font-size:12px;margin-top:40px}
</style>
</head>
<body>
<div class="shell">
  <header class="brand">
    <div class="logo"></div>
    <div>
      <h1>Reprint</h1>
      <div class="subtitle">Clone a WordPress.com site into Playground</div>
    </div>
    <?php if ($authed): ?>
      <div class="user-pill" id="user-pill"><span>Signed in to WordPress.com</span> · <a href="?action=logout">Not you?</a></div>
    <?php endif; ?>
  </header>

  <div class="stepper">
    <div class="step <?= $authed ? 'done' : 'active' ?>" data-step="1"><span class="num">1</span>Authorize</div>
    <div class="step <?= $authed ? 'active' : '' ?>" data-step="2"><span class="num">2</span>Pick a site</div>
    <div class="step" data-step="3"><span class="num">3</span>Clone into Playground</div>
  </div>

  <!-- STEP 1 — Connect WP.com -->
  <section class="card <?= $authed ? 'hidden' : '' ?>" id="card-1">
    <h2>Connect your WordPress.com account</h2>
    <p class="desc">We'll redirect you to WordPress.com to authorize Reprint.
      This lets us list your sites and temporarily enable the reprint exporter
      on the one you choose. Your access token is kept in a session cookie on
      this site only (HttpOnly + encrypted with AES-256-GCM, never written to
      a database). The cookie has no expiry — it sticks around until you
      click "Sign out" or your browser drops session cookies. Most browsers
      drop them on quit, but if you've enabled "continue where you left off"
      (Chrome) or have multiple windows open (Firefox), they can persist
      across restarts. "Sign out" is the only guarantee.</p>
    <div class="actions">
      <a href="?action=login"><button class="primary">Sign in with WordPress.com →</button></a>
    </div>
  </section>

  <!-- STEP 2 — Pick site -->
  <section class="card <?= $authed ? '' : 'hidden' ?>" id="card-2">
    <h2>Choose a site to clone</h2>
    <p class="desc">Atomic sites can be cloned with reprint. Simple WordPress.com sites are listed for reference but are dimmed and not selectable — they don't run wpcomsh's export endpoint. The exporter is enabled on the site you pick for a rolling 60-minute window.</p>
    <input type="search" id="site-filter" placeholder="Filter by name or URL…" autocomplete="off" class="hidden">
    <div class="site-list" id="site-list"><p class="desc" id="sites-loading">Loading your sites…</p></div>
    <p class="desc site-count hidden" id="site-count" style="margin:10px 0 0;display:flex;align-items:center;gap:8px">
      <span id="site-count-text"></span>
      <span id="site-refresh" class="hidden" title="Refreshing site list from WordPress.com…">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 0.9s linear infinite">
          <path d="M21 12a9 9 0 1 1-6.2-8.55"></path>
          <path d="M21 4v5h-5"></path>
        </svg>
      </span>
    </p>
    <div class="actions">
      <button class="primary" id="start-btn" disabled>Start import →</button>
      <span class="subtitle" id="start-hint"></span>
    </div>
    <script>
      // Synchronous cache hydrate. Placed AFTER all referenced
      // elements (site-list, site-filter, site-count, site-count-text)
      // so getElementById always succeeds. Renders minimal-HTML rows
      // before the bottom-of-body JS upgrades them to interactive ones.
      (function () {
        try {
          const key = 'reprint:sites:' + (window.__REPRINT_USER_ID__ || 'anon');
          const raw = localStorage.getItem(key);
          if (!raw) return;
          const parsed = JSON.parse(raw);
          const sites = parsed && parsed.sites;
          if (!Array.isArray(sites) || !sites.length) return;
          const sorted = sites.slice().sort((a, b) =>
            (b.is_wpcom_atomic ? 1 : 0) - (a.is_wpcom_atomic ? 1 : 0));
          const esc = (s) => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
          document.getElementById('site-list').innerHTML = sorted.map((s) => {
            const atomic = !!s.is_wpcom_atomic;
            const icon = s.icon && s.icon.img
              ? '<img src="' + esc(s.icon.img) + '" alt="">'
              : '<div class="site-icon">' + esc((s.name || '?')[0]) + '</div>';
            const reason = atomic ? ''
              : '<div class="reason">Reprint requires Atomic hosting — Simple WordPress.com sites can\'t be cloned.</div>';
            return '<div class="site ' + (atomic ? '' : 'disabled') + '" data-pending="1">'
              + icon
              + '<div>'
              +   '<div class="site-title">' + esc(s.name || s.URL || 'Untitled') + '</div>'
              +   '<div class="site-url">' + esc(s.URL) + '</div>'
              +   reason
              + '</div>'
              + '<span class="badge">' + (atomic ? 'Atomic' : 'Simple') + '</span>'
              + '</div>';
          }).join('');
          document.getElementById('site-filter').classList.remove('hidden');
          document.getElementById('site-count').classList.remove('hidden');
          document.getElementById('site-count-text').textContent = sites.length + ' sites';
          window.__REPRINT_CACHED_SITES__ = sites;
        } catch (e) { console.warn('reprint cache hydrate failed:', e); }
      })();
    </script>
  </section>

  <!-- STEP 3 — Provision + handoff to Playground -->
  <section class="card hidden" id="card-3">
    <h2>Authorising exporter…</h2>
    <p class="desc" id="playground-desc">We're enabling the reprint exporter on your site, then handing the import off to WordPress Playground in this tab.</p>
    <div class="progress-list" id="phases">
      <div class="phase" data-phase="provision"><div class="dot"></div><div class="name">Authorise exporter on WordPress.com</div><div class="meta">—</div></div>
      <div class="phase" data-phase="handoff">  <div class="dot"></div><div class="name">Open Playground (this tab will navigate)</div><div class="meta">—</div></div>
    </div>
    <div class="log" id="log"></div>
    <div id="error-banner" class="hidden" style="margin-top:16px;padding:14px 16px;background:rgba(255,107,107,.08);border:1px solid rgba(255,107,107,.4);border-radius:10px;color:#ffb0b0;font-size:13px;line-height:1.5"></div>
    <div class="actions">
      <button class="ghost hidden" id="retry-btn">← Pick a different site</button>
    </div>
  </section>

  <footer>Powered by <code>reprint.phar</code> · HMAC secrets rotate per import</footer>
</div>

<script>
const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);
const UI_BASE = <?= json_encode(REPRINT_UI_URL_BASE) ?>;

function setStep(n) {
  $$('.step').forEach(el => {
    const i = +el.dataset.step;
    el.classList.toggle('active', i === n);
    el.classList.toggle('done', i < n);
  });
  [1,2,3].forEach(i => $('#card-' + i).classList.toggle('hidden', i !== n));
}
function logLine(text, cls) {
  const el = $('#log');
  const div = document.createElement('div');
  if (cls) div.className = cls;
  div.textContent = text;
  el.appendChild(div);
  el.scrollTop = el.scrollHeight;
}
function setPhase(phase, state, meta) {
  const el = document.querySelector(`[data-phase="${phase}"]`);
  if (!el) return;
  el.classList.remove('active', 'done', 'error');
  if (state) el.classList.add(state);
  if (meta != null) el.querySelector('.meta').textContent = meta;
}
function fmtBytes(n){if(n<1024)return n+' B';if(n<1048576)return (n/1024).toFixed(1)+' KB';if(n<1073741824)return (n/1048576).toFixed(1)+' MB';return (n/1073741824).toFixed(2)+' GB';}

let selectedSiteId = null;
let selectedSiteUrl = null;

<?php if ($authed): ?>
(async function loadMe() {
  try {
    const res = await fetch(UI_BASE + '?action=me');
    if (!res.ok) return;
    const me = await res.json();
    const pill = $('#user-pill');
    if (!pill || !me) return;
    const name = me.display_name || me.username || 'WordPress.com user';
    const avatar = me.avatar_URL ? `<img class="avatar" src="${me.avatar_URL}" alt="">` : '';
    pill.innerHTML = `${avatar}<span>Signed in as <strong>${name.replace(/</g,'&lt;')}</strong></span> · <a href="?action=logout">Not you?</a>`;
  } catch {}
})();
// Hoisted so the loadSites IIFE below isn't blocked by the TDZ.
var ALL_SITES = [];
function sitesFingerprint(sites) {
  return (sites || [])
    .map(s => `${s.ID}|${s.URL}|${s.name}|${s.is_wpcom_atomic ? 1 : 0}|${s.icon?.img || ''}`)
    .sort()
    .join('::');
}

(async function loadSites() {
  setStep(2);

  // The inline <script> next to #site-list already painted the cached
  // rows (or did nothing if no cache). Upgrade them to fully
  // interactive ones, then refetch in the background.
  const cacheKey = 'reprint:sites:' + (window.__REPRINT_USER_ID__ || 'anon');
  const cachedSites = window.__REPRINT_CACHED_SITES__;
  let renderedFingerprint = '';
  if (Array.isArray(cachedSites) && cachedSites.length) {
    renderSites(cachedSites);
    renderedFingerprint = sitesFingerprint(cachedSites);
    $('#site-refresh').classList.remove('hidden');
  }

  try {
    const res = await fetch(UI_BASE + '?action=sites');
    if (res.status === 401) { location.href = UI_BASE + '?action=login'; return; }
    const data = await res.json();
    const sites = Array.isArray(data) ? data : (data.sites || []);
    try { localStorage.setItem(cacheKey, JSON.stringify({ sites, ts: Date.now() })); } catch {}
    // Skip re-render when nothing meaningful changed — keeps the UI
    // stable across the background refresh, no "jump" when fresh
    // data arrives with the same sites.
    const liveFingerprint = sitesFingerprint(sites);
    if (liveFingerprint !== renderedFingerprint) {
      renderSites(sites);
    }
  } catch (e) {
    if (!cachedSites) {
      $('#site-list').innerHTML = '<p class="desc" style="color:var(--err)">Failed to load sites: ' + e.message + '</p>';
    }
    // If we had a cache, leave it on screen — better than wiping it.
  } finally {
    $('#site-refresh').classList.add('hidden');
  }
})();
<?php endif; ?>

// ALL_SITES is hoisted above the loadSites IIFE — declaring it again
// here would shadow the assignment.
function renderSites(sites) {
  // Atomic sites first (those are the importable ones), Simple sites
  // last so they don't crowd the picker. Within each group, keep the
  // server-given order (recently active first).
  ALL_SITES = (sites || []).slice().sort((a, b) => {
    const aA = a.is_wpcom_atomic ? 1 : 0;
    const bA = b.is_wpcom_atomic ? 1 : 0;
    return bA - aA;
  });
  const list = $('#site-list');
  const filter = $('#site-filter');
  const count = $('#site-count');
  if (!ALL_SITES.length) {
    list.innerHTML = '<p class="desc">No Atomic or WordPress.com sites found on this account.</p>';
    return;
  }
  filter.classList.remove('hidden');
  count.classList.remove('hidden');
  // Bind the input listener once. Refocusing on every render would
  // steal focus from the user mid-typing when the background refresh
  // returns, so only focus on the very first call.
  if (!filter.dataset.bound) {
    filter.addEventListener('input', applySiteFilter);
    filter.dataset.bound = '1';
    filter.focus();
  }
  applySiteFilter();
}

function applySiteFilter() {
  const q = ($('#site-filter').value || '').trim().toLowerCase();
  const filtered = q
    ? ALL_SITES.filter(s => (s.name || '').toLowerCase().includes(q) || (s.URL || '').toLowerCase().includes(q))
    : ALL_SITES;

  const list = $('#site-list');
  list.innerHTML = '';
  if (!filtered.length) {
    list.innerHTML = '<p class="desc">No sites match that filter.</p>';
  } else {
    filtered.forEach(s => list.appendChild(renderSiteRow(s)));
  }
  $('#site-count-text').textContent = q
    ? `${filtered.length} of ${ALL_SITES.length} sites`
    : `${ALL_SITES.length} sites`;

  // If the previously selected site got filtered out, clear selection.
  if (selectedSiteId && !filtered.some(s => s.ID === selectedSiteId)) {
    selectedSiteId = null;
    $('#start-btn').disabled = true;
    $('#start-hint').textContent = '';
  }
}

function renderSiteRow(s) {
  const el = document.createElement('div');
  el.className = 'site';
  const isAtomic = !!s.is_wpcom_atomic;
  if (!isAtomic) el.classList.add('disabled');
  if (s.ID === selectedSiteId) el.classList.add('selected');
  el.dataset.siteId = s.ID;
  const reason = isAtomic ? '' :
    `<div class="reason">Reprint requires Atomic hosting — Simple WordPress.com sites can't be cloned.</div>`;
  el.innerHTML = `
    ${s.icon && s.icon.img ? `<img src="${s.icon.img}" alt="">` : `<div class="site-icon">${(s.name||'?')[0]}</div>`}
    <div>
      <div class="site-title">${(s.name || s.URL || 'Untitled').replace(/</g,'&lt;')}</div>
      <div class="site-url">${(s.URL || '').replace(/</g,'&lt;')}</div>
      ${reason}
    </div>
    <span class="badge">${isAtomic ? 'Atomic' : 'Simple'}</span>`;
  if (!isAtomic) {
    el.title = 'Reprint requires Atomic hosting';
    return el;
  }
  el.addEventListener('click', () => {
    $$('.site').forEach(x => x.classList.remove('selected'));
    el.classList.add('selected');
    selectedSiteId = s.ID;
    selectedSiteUrl = s.URL;
    $('#start-btn').disabled = false;
    $('#start-hint').textContent = `Will clone ${s.URL}`;
  });
  return el;
}

$('#start-btn')?.addEventListener('click', async () => {
  if (!selectedSiteId) return;
  setStep(3);
  $('#log').innerHTML = '';
  ['provision','handoff'].forEach(p => setPhase(p, '', '—'));
  $('#error-banner').classList.add('hidden');
  $('#retry-btn').classList.add('hidden');

  setPhase('provision', 'active', 'Enabling exporter & rotating secret…');
  logLine('Enabling the exporter and rotating the secret for site ' + selectedSiteId + ' on wp.com…');

  let provisioned;
  try {
    const res = await fetch(UI_BASE + '?action=provision', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'site_id=' + encodeURIComponent(selectedSiteId)
          + '&site_url=' + encodeURIComponent(selectedSiteUrl || ''),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || 'HTTP ' + res.status);
    }
    provisioned = await res.json();
  } catch (e) {
    setPhase('provision', 'error', e.message.slice(0, 80));
    showImportError('Provision failed: ' + e.message);
    return;
  }

  setPhase('provision', 'done', 'authorized');
  logLine('api_url=' + provisioned.api_url);
  logLine('secret length=' + provisioned.secret.length, 'info');

  goToPlayground(provisioned);
});

/**
 * Build the Playground URL and navigate the wizard tab to it.
 *
 * The Blueprint URL is the wizard's `?action=blueprint` endpoint —
 * Playground fetches it server-side, gets back JSON describing the
 * importer page + reprint.phar to drop into the WASM filesystem, and
 * lands on /reprint-import.php.
 *
 * The api_url + secret travel in the URL fragment (#api=...&secret=...).
 * Fragments are JS-only, so they never reach a server log: the
 * importer page reads window.location.hash, then POSTs them to itself
 * for the actual import. Same tab navigation makes the "do not
 * navigate away" warning meaningful — opening a new tab would let the
 * user accidentally close it.
 */
function goToPlayground({ api_url, secret, site_url }) {
  setPhase('handoff', 'active', 'opening Playground…');
  logLine('Handing off to Playground (this tab will navigate)…', 'info');

  const sourceOrigin = (function () {
    try { return new URL(site_url).origin; } catch { return ''; }
  })();

  // Credentials travel as query params on the blueprint URL itself.
  // We tried fragments first — but Playground's outer page strips the
  // hash before the iframe loads, so the importer page (running inside
  // the iframe) couldn't see them. The blueprint endpoint reads these
  // server-side and bakes the values into the importer page literal.
  // Both this host and playground.wordpress.net are Automattic-operated,
  // and the secret is a 60-minute rotating HMAC bound to one site, so
  // the slight access-log exposure is acceptable.
  const blueprintQuery = new URLSearchParams({
    action: 'blueprint',
    api: api_url,
    secret: secret,
    source: sourceOrigin,
  }).toString();
  const blueprintUrl = location.origin + UI_BASE + '?' + blueprintQuery;

  const playgroundUrl = 'https://playground.wordpress.net/'
    + '?blueprint-url=' + encodeURIComponent(blueprintUrl);

  // Brief visible delay so the user sees the "opening Playground…"
  // state before the page navigates away. Otherwise the wizard just
  // appears to disappear.
  setTimeout(() => { window.location.href = playgroundUrl; }, 300);
}


function showImportError(msg) {
  const banner = $('#error-banner');
  banner.textContent = msg;
  banner.classList.remove('hidden');
  $('#retry-btn').classList.remove('hidden');
}

$('#retry-btn')?.addEventListener('click', () => {
  $('#error-banner').classList.add('hidden');
  $('#retry-btn').classList.add('hidden');
  ['provision','handoff'].forEach(p => setPhase(p, '', '—'));
  setStep(2);
});

</script>
</body>
</html>
    <?php
}
