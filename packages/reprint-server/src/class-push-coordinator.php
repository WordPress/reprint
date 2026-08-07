<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Authenticated push protocol errors are JSON responses, not rendered HTML.

use function WordPress\Filesystem\wp_join_unix_paths;

if (!class_exists('Site_Export_Push_Exception', false)) {
    require_once __DIR__ . '/class-push-exception.php';
}

/**
 * Stores the durable owner and document-root generation for one push target.
 *
 * The coordination state outlives HTTP requests. Short-lived flock gates only
 * serialize metadata, owner requests, and document-root reads or mutations.
 */
final class Site_Export_Push_Coordinator {

    private const TERMINAL_OWNER_LIMIT = 8;

    /** @var string */
    private $directory;
    /** @var string */
    private $state_path;
    /** @var string */
    private $state_lock_path;
    /** @var string */
    private $push_request_lock_path;
    /** @var string */
    private $file_read_lock_path;
    /** @var string */
    private $docroot_b64;
    /** @var list<string> */
    private $excluded_paths_b64;

    /**
     * @param string $reprint_directory Private reprint directory.
     * @param string $docroot Document root controlled by this coordinator.
     * @param list<string> $excluded_paths Immutable push exclusions.
     */
    public function __construct(string $reprint_directory, string $docroot, array $excluded_paths) {
        $this->directory = wp_join_unix_paths($reprint_directory, '.reprint', 'push');
        $this->state_path = wp_join_unix_paths($this->directory, 'state.json');
        $this->state_lock_path = wp_join_unix_paths($this->directory, 'state.lock');
        $this->push_request_lock_path = wp_join_unix_paths($this->directory, 'push-request.lock');
        $this->file_read_lock_path = wp_join_unix_paths($this->directory, 'file-read.lock');
        $this->docroot_b64 = base64_encode($docroot);
        $this->excluded_paths_b64 = array_map('base64_encode', $excluded_paths);
    }

    /**
     * Claims ownership for a new push session or returns its existing identity.
     *
     * @return array{ownership_epoch:int,document_root_generation:int,phase:string}
     */
    public function claim_owner(
        string $push_session_id,
        bool $force_takeover,
        ?string $blocking_push_session_id,
        ?int $blocking_ownership_epoch
    ): array {
        return $this->with_state_lock(function () use ($push_session_id, $force_takeover, $blocking_push_session_id, $blocking_ownership_epoch): array {
            $state = $this->read_state();
            $this->assert_configuration($state);
            foreach ([$this->push_request_lock_path, $this->file_read_lock_path] as $lock_path) {
                $lock = $this->open_lock($lock_path, 'coordination');
                fclose($lock);
            }
            $owner = $state['owner'];
            if ($owner !== null && $owner['push_session_id'] === $push_session_id) {
                return [
                    'ownership_epoch' => $owner['ownership_epoch'],
                    'document_root_generation' => $state['document_root_generation'],
                    'phase' => $state['phase'],
                ];
            }
            if ($owner !== null) {
                if (
                    !$force_takeover
                    || $blocking_push_session_id !== $owner['push_session_id']
                    || $blocking_ownership_epoch !== $owner['ownership_epoch']
                ) {
                    throw new Site_Export_Push_Exception(
                        Site_Export_Push_Session::ERROR_SYNC_LOCKED,
                        'Push session ' . $owner['push_session_id'] . ' owns this document root at ownership epoch ' . $owner['ownership_epoch'] . '.',
                        [
                            'blocking_push_session_id' => $owner['push_session_id'],
                            'blocking_ownership_epoch' => $owner['ownership_epoch'],
                        ]
                    );
                }
                $state['terminal_owners'][] = [
                    'push_session_id' => $owner['push_session_id'],
                    'ownership_epoch' => $owner['ownership_epoch'],
                    'result' => 'displaced',
                ];
                $state['terminal_owners'] = array_slice($state['terminal_owners'], -self::TERMINAL_OWNER_LIMIT);
                $state['recovery'] = [
                    'displaced_push_session_id' => $owner['push_session_id'],
                    'displaced_ownership_epoch' => $owner['ownership_epoch'],
                ];
                $state['phase'] = 'recovering_displaced_push';
            } else {
                $state['phase'] = 'creating_push';
            }
            ++$state['ownership_epoch'];
            $state['owner'] = [
                'push_session_id' => $push_session_id,
                'ownership_epoch' => $state['ownership_epoch'],
            ];
            $this->write_state($state);
            return [
                'ownership_epoch' => $state['ownership_epoch'],
                'document_root_generation' => $state['document_root_generation'],
                'phase' => $state['phase'],
            ];
        });
    }

    /**
     * Releases a claim when private push-session creation could not begin.
     */
    public function abandon_creation(string $push_session_id, int $ownership_epoch): void {
        $this->with_state_lock(function () use ($push_session_id, $ownership_epoch): void {
            $state = $this->read_state();
            $this->assert_configuration($state);
            if (
                $state['owner'] === null
                || $state['owner']['push_session_id'] !== $push_session_id
                || $state['owner']['ownership_epoch'] !== $ownership_epoch
                || !in_array($state['phase'], ['creating_push', 'recovering_displaced_push'], true)
            ) {
                return;
            }
            $state['owner'] = null;
            $state['phase'] = 'idle';
            $state['recovery'] = null;
            $this->write_state($state);
        });
    }

    /**
     * Runs one owner request while holding the request gate for its full duration.
     *
     * @param callable $callback
     * @return mixed
     */
    public function with_owner_request(string $push_session_id, int $ownership_epoch, callable $callback) {
        $lock = $this->open_lock($this->push_request_lock_path, 'push request');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new Site_Export_Push_Exception(
                    Site_Export_Push_Session::ERROR_SYNC_LOCKED,
                    'Another push request is still operating on this document root.'
                );
            }
            $this->assert_owner($push_session_id, $ownership_epoch);
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Marks a request as receiving private work after it passed owner fencing.
     */
    public function mark_receiving_work(string $push_session_id, int $ownership_epoch): void {
        $this->change_phase($push_session_id, $ownership_epoch, 'receiving_work');
    }

    /**
     * Marks commit intent before acquiring the exclusive document-root gate.
     *
     * @param callable $callback
     * @return mixed
     */
    public function with_commit(string $push_session_id, int $ownership_epoch, callable $callback) {
        $this->with_state_lock(function () use ($push_session_id, $ownership_epoch): void {
            $state = $this->read_state();
            $this->assert_configuration($state);
            $this->assert_state_owner($state, $push_session_id, $ownership_epoch);
            if ($state['phase'] !== 'finalizing') {
                $state['phase'] = 'committing';
                $this->write_state($state);
            }
        });
        $lock = $this->open_lock($this->file_read_lock_path, 'file-read');
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new Site_Export_Push_Exception(Site_Export_Push_Session::ERROR_FILESYSTEM, 'Could not acquire the document-root mutation gate.');
            }
            $this->assert_owner($push_session_id, $ownership_epoch);
            $result = $callback();
            if (is_array($result) && ( $result['phase'] ?? null ) === 'complete') {
                $this->complete_commit($push_session_id, $ownership_epoch);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Holds a shared document-root gate while a pull response streams.
     *
     * @param callable $callback
     * @return mixed
     */
    public function with_file_read(callable $callback) {
        $this->assert_reads_open();
        $lock = $this->open_lock($this->file_read_lock_path, 'file-read');
        try {
            if (!flock($lock, LOCK_SH)) {
                throw new Site_Export_Push_Exception(Site_Export_Push_Session::ERROR_FILESYSTEM, 'Could not acquire the document-root read gate.');
            }
            $this->assert_reads_open();
            return $callback($this->get_document_root_generation());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Clears a completed owner only after its private work was removed.
     */
    public function complete_removal(string $push_session_id, int $ownership_epoch): void {
        $this->with_state_lock(function () use ($push_session_id, $ownership_epoch): void {
            $state = $this->read_state();
            $this->assert_configuration($state);
            if ($state['owner'] === null) {
                foreach ($state['terminal_owners'] as $terminal_owner) {
                    if (
                        is_array($terminal_owner)
                        && ( $terminal_owner['push_session_id'] ?? null ) === $push_session_id
                        && ( $terminal_owner['ownership_epoch'] ?? null ) === $ownership_epoch
                        && ( $terminal_owner['result'] ?? null ) === 'complete'
                    ) {
                        return;
                    }
                }
            }
            $this->assert_state_owner($state, $push_session_id, $ownership_epoch);
            $state['terminal_owners'][] = [
                'push_session_id' => $push_session_id,
                'ownership_epoch' => $ownership_epoch,
                'result' => 'complete',
            ];
            $state['terminal_owners'] = array_slice($state['terminal_owners'], -self::TERMINAL_OWNER_LIMIT);
            $state['owner'] = null;
            $state['phase'] = 'idle';
            $state['recovery'] = null;
            $this->write_state($state);
        });
    }

    /**
     * Reports whether a former owner completed private-work removal.
     */
    public function is_terminal_owner(string $push_session_id, int $ownership_epoch): bool {
        return $this->with_state_lock(function () use ($push_session_id, $ownership_epoch): bool {
            $state = $this->read_state();
            $this->assert_configuration($state);
            foreach ($state['terminal_owners'] as $terminal_owner) {
                if (
                    is_array($terminal_owner)
                    && ( $terminal_owner['push_session_id'] ?? null ) === $push_session_id
                    && ( $terminal_owner['ownership_epoch'] ?? null ) === $ownership_epoch
                    && ( $terminal_owner['result'] ?? null ) === 'complete'
                ) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Returns the generation attached to a file response.
     */
    public function get_document_root_generation(): int {
        return $this->with_state_lock(function (): int {
            $state = $this->read_state();
            $this->assert_configuration($state);
            return $state['document_root_generation'];
        });
    }

    private function complete_commit(string $push_session_id, int $ownership_epoch): void {
        $this->with_state_lock(function () use ($push_session_id, $ownership_epoch): void {
            $state = $this->read_state();
            $this->assert_configuration($state);
            $this->assert_state_owner($state, $push_session_id, $ownership_epoch);
            if ($state['phase'] !== 'finalizing') {
                ++$state['document_root_generation'];
                $state['phase'] = 'finalizing';
                $this->write_state($state);
            }
        });
    }

    private function change_phase(string $push_session_id, int $ownership_epoch, string $phase): void {
        $this->with_state_lock(function () use ($push_session_id, $ownership_epoch, $phase): void {
            $state = $this->read_state();
            $this->assert_configuration($state);
            $this->assert_state_owner($state, $push_session_id, $ownership_epoch);
            if ($state['phase'] !== $phase) {
                $state['phase'] = $phase;
                $this->write_state($state);
            }
        });
    }

    private function assert_owner(string $push_session_id, int $ownership_epoch): void {
        $this->with_state_lock(function () use ($push_session_id, $ownership_epoch): void {
            $state = $this->read_state();
            $this->assert_configuration($state);
            $this->assert_state_owner($state, $push_session_id, $ownership_epoch);
        });
    }

    private function assert_reads_open(): void {
        $this->with_state_lock(function (): void {
            $state = $this->read_state();
            $this->assert_configuration($state);
            if ($state['phase'] === 'committing' || $state['phase'] === 'commit_failed') {
                throw new Site_Export_Push_Exception(
                    Site_Export_Push_Session::ERROR_SYNC_LOCKED,
                    'File synchronization is unavailable while the document root is committing.'
                );
            }
        });
    }

    /**
     * @param array<string,mixed> $state
     */
    private function assert_configuration(array $state): void {
        if ($state['docroot_b64'] !== $this->docroot_b64 || $state['excluded_paths_b64'] !== $this->excluded_paths_b64) {
            throw new Site_Export_Push_Exception(
                Site_Export_Push_Session::ERROR_PUSH_CONFIGURATION_CHANGED,
                'The document root or excluded paths changed while Reprint coordination state exists.'
            );
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    private function assert_state_owner(array $state, string $push_session_id, int $ownership_epoch): void {
        $owner = $state['owner'];
        if (
            $owner === null
            || $owner['push_session_id'] !== $push_session_id
            || $owner['ownership_epoch'] !== $ownership_epoch
        ) {
            throw new Site_Export_Push_Exception(
                Site_Export_Push_Session::ERROR_SYNC_OVERTAKEN,
                'This push session no longer owns the document root at the supplied ownership epoch.'
            );
        }
    }

    /**
     * @param callable $callback
     * @return mixed
     */
    private function with_state_lock(callable $callback) {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new Site_Export_Push_Exception(Site_Export_Push_Session::ERROR_FILESYSTEM, 'Could not create the push coordination directory.');
        }
        $lock = $this->open_lock($this->state_lock_path, 'state');
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new Site_Export_Push_Exception(Site_Export_Push_Session::ERROR_FILESYSTEM, 'Could not acquire the push coordination state lock.');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return resource
     */
    private function open_lock(string $path, string $name) {
        $lock = @fopen($path, 'c+b');
        if ($lock === false) {
            throw new Site_Export_Push_Exception(Site_Export_Push_Session::ERROR_FILESYSTEM, 'Could not open the ' . $name . ' coordination lock.');
        }
        return $lock;
    }

    /**
     * @return array<string,mixed>
     */
    private function read_state(): array {
        if (!file_exists($this->state_path)) {
            return [
                'docroot_b64' => $this->docroot_b64,
                'excluded_paths_b64' => $this->excluded_paths_b64,
                'document_root_generation' => 0,
                'ownership_epoch' => 0,
                'owner' => null,
                'phase' => 'idle',
                'recovery' => null,
                'terminal_owners' => [],
            ];
        }
        $json = @file_get_contents($this->state_path);
        $state = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($state)
            || !is_string($state['docroot_b64'] ?? null)
            || !is_array($state['excluded_paths_b64'] ?? null)
            || !is_int($state['document_root_generation'] ?? null)
            || !is_int($state['ownership_epoch'] ?? null)
            || !array_key_exists('owner', $state)
            || !is_string($state['phase'] ?? null)
            || !is_array($state['terminal_owners'] ?? null)
        ) {
            throw new Site_Export_Push_Exception(Site_Export_Push_Session::ERROR_CORRUPTED_PUSH_STATE, 'Push coordination state is malformed.');
        }
        if ($state['owner'] !== null
            && ( !is_array($state['owner'])
                || !is_string($state['owner']['push_session_id'] ?? null)
                || !is_int($state['owner']['ownership_epoch'] ?? null) )) {
            throw new Site_Export_Push_Exception(Site_Export_Push_Session::ERROR_CORRUPTED_PUSH_STATE, 'Push coordination owner is malformed.');
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function write_state(array $state): void {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new Site_Export_Push_Exception(Site_Export_Push_Session::ERROR_FILESYSTEM, 'Could not encode push coordination state.');
        }
        $temporary_path = $this->state_path . '.swap';
        if (@file_put_contents($temporary_path, $json . "\n", LOCK_EX) === false || !@rename($temporary_path, $this->state_path)) {
            @unlink($temporary_path);
            throw new Site_Export_Push_Exception(Site_Export_Push_Session::ERROR_FILESYSTEM, 'Could not atomically store push coordination state.');
        }
    }
}
