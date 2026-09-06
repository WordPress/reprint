<?php
/** Pause a real database apply at a state-save boundary; the E2E parent kills it. */
$reprint_client_path = $argv[1];
require substr($reprint_client_path, -5) === '.phar'
    ? 'phar://' . $reprint_client_path . '/packages/reprint-client/src/import.php'
    : dirname($reprint_client_path) . '/../src/import.php';

class ReprintMultisiteApplyPause extends ImportClient {
    public function save_state(): void {
        global $argv;
        $active = $this->get_state()->active_resumable_command;
        $pause = $active->command_name === 'db-apply' && $active->current_stage === $argv[5];
        if (!$pause || $argv[6] === 'after') {
            parent::save_state();
        }
        if ($pause) {
            file_put_contents($argv[7], 'paused');
            while (true) {
                usleep(100000);
            }
        }
    }
}

$reprint_client = new ReprintMultisiteApplyPause($argv[2], $argv[3], $argv[3] . '/fs-root');
$reprint_client->run([
    'command' => 'db-apply',
    'target_engine' => 'mysql', 'target_host' => '127.0.0.1',
    'target_user' => 'e2e_admin', 'target_pass' => 'e2e_password', 'target_db' => $argv[4],
    'new_site_url' => 'http://localhost:9247', 'network_admin' => 'shared',
]);
