<?php
/**
 * WordPress.com OAuth redirect handler.
 *
 * Registered redirect URI for our WP.com app. Exchanges the authorization
 * code for an access token (server-side, using the client secret stored
 * in wp_options), stores the token in an encrypted cookie, and bounces back to
 * the wizard.
 */

require __DIR__ . '/reprint-ui/bootstrap.php';

// Abort on OAuth error responses so the user sees something useful.
if (!empty($_GET['error'])) {
    http_response_code(400);
    $err = htmlspecialchars((string) $_GET['error'], ENT_QUOTES, 'UTF-8');
    $desc = htmlspecialchars((string) ($_GET['error_description'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><meta charset=utf-8><title>Authorization failed</title>";
    echo "<p>WordPress.com returned: <strong>$err</strong></p><p>$desc</p>";
    echo "<p><a href=\"/reprint.php\">Try again</a></p>";
    exit;
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');

if ($code === '') {
    http_response_code(400);
    echo 'Missing code parameter.';
    exit;
}

$state_cookie = reprint_cookie_get('rp_state');
$expected_state = is_array($state_cookie) ? (string) ($state_cookie['s'] ?? '') : '';
if ($expected_state === '' || !hash_equals($expected_state, $state)) {
    http_response_code(400);
    echo 'State mismatch. Start over at <a href="/reprint.php">/reprint.php</a>.';
    exit;
}
reprint_cookie_clear('rp_state');

[$client_id, $client_secret] = reprint_client_credentials();
$redirect_uri = reprint_redirect_uri();

$ch = curl_init('https://public-api.wordpress.com/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code',
        'code' => $code,
    ]),
    CURLOPT_TIMEOUT => 20,
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false || $status < 200 || $status >= 300) {
    http_response_code(502);
    echo "<!doctype html><meta charset=utf-8><title>Token exchange failed</title>";
    echo "<p>HTTP $status from WP.com token endpoint.</p>";
    echo "<pre>" . htmlspecialchars($err ?: (string) $body, ENT_QUOTES, 'UTF-8') . "</pre>";
    exit;
}

$data = json_decode((string) $body, true);
if (!is_array($data) || empty($data['access_token'])) {
    http_response_code(502);
    echo 'Malformed token response.';
    exit;
}

// Park the token in an encrypted, HttpOnly cookie. Nothing about this
// token ever touches the database or server-side session storage.
reprint_cookie_set('rp_tok', [
    'token'   => (string) $data['access_token'],
    'blog_id' => isset($data['blog_id']) ? (int) $data['blog_id'] : null,
    'scope'   => (string) ($data['scope'] ?? ''),
], 0);

header('Location: /reprint.php');
exit;
