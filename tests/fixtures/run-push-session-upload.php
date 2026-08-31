<?php

// Run one upload through the production push-session API. The parent PHPUnit
// process injects a filesystem failure and then examines the durable result.
require_once dirname(__DIR__) . '/bootstrap.php';

$reprint_test_configuration = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
$reprint_test_push_session = \WordPress\Reprint\Server\PushSession::open(
    $reprint_test_configuration['reprint_directory'],
    $reprint_test_configuration['docroot'],
    $reprint_test_configuration['push_session_id'],
    $reprint_test_configuration['excluded_paths']
);
$reprint_test_input = fopen('php://temp', 'w+b');
fwrite($reprint_test_input, base64_decode($reprint_test_configuration['body_b64'], true));
rewind($reprint_test_input);
$reprint_test_upload_open = false;

try {
    $reprint_test_push_session->accept_upload($reprint_test_input, new \WordPress\Reprint\Server\MultipartProcessor($reprint_test_configuration['boundary']));
    $reprint_test_upload_open = true;
    while (true) {
        if (!$reprint_test_push_session->next_change()) {
            break;
        }
    }
    $reprint_test_push_session->finish_upload();
    $reprint_test_upload_open = false;
    fclose($reprint_test_input);
    echo json_encode(['result' => 'accepted'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $exception) {
    if ($reprint_test_upload_open) {
        $reprint_test_push_session->finish_upload();
    }
    fclose($reprint_test_input);
    echo json_encode([
        'class' => get_class($exception),
        'reason' => $exception instanceof \WordPress\Reprint\Server\PushException ? $exception->get_error_code() : null,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(73);
}
