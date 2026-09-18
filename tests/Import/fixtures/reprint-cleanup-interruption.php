<?php

require dirname(__DIR__, 3) . '/packages/reprint-client/src/import.php';

/** Kill the actual importer at a cleanup boundary, without running shutdown code. */
class InterruptedReprintCleanupClient extends ImportClient
{
    public string $boundary;
    public string $marker;

    /** Keep only the checkpoint which production saved before the selected kill. */
    public function save_state(): void
    {
        $state = $this->get_state()->active_resumable_command;
        if ($this->boundary === 'after-cleanup' && $state->current_stage === 'database-cleanup' && $state->completion_state === 'complete') {
            file_put_contents($this->marker, $this->boundary);
            posix_kill(getmypid(), 9);
        }
        parent::save_state();
        if ($this->boundary === 'before-cleanup' && $state->current_stage === 'database-cleanup') {
            file_put_contents($this->marker, $this->boundary);
            posix_kill(getmypid(), 9);
        }
    }
}

$client = new InterruptedReprintCleanupClient($argv[1], $argv[2] . '/state', $argv[2] . '/files');
$client->boundary = $argv[3];
$client->marker = $argv[2] . '/killed-at-cleanup';
$client->run(json_decode($argv[4], true));
