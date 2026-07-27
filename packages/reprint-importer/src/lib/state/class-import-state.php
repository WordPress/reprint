<?php
/**
 * Typed import-state objects.
 *
 * The importer persists state as JSON, so these objects keep explicit
 * in-process property names while to_array()/from_array() preserve the stable
 * on-disk schema.
 */

class ResumableCommandCheckpointState
{
    /** @var string|null Lower-level command name, e.g. files-pull/db-pull/db-apply. */
    public ?string $command_name = null;

    /** @var string|null Completion state: in_progress, partial, complete, or null before start. */
    public ?string $completion_state = null;

    /** @var string|null Internal stage within the active command. */
    public ?string $current_stage = null;

    /** @var string|null Remote pagination cursor for resumable endpoints. */
    public ?string $remote_cursor = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        $state->command_name = isset($data['command_name']) ? (string) $data['command_name'] : null;
        $state->completion_state = isset($data['completion_state']) ? (string) $data['completion_state'] : null;
        $state->current_stage = isset($data['current_stage']) ? (string) $data['current_stage'] : null;
        $state->remote_cursor = isset($data['remote_cursor']) ? (string) $data['remote_cursor'] : null;
        return $state;
    }

    public function to_array(): array
    {
        return [
            'command_name' => $this->command_name,
            'completion_state' => $this->completion_state,
            'current_stage' => $this->current_stage,
            'remote_cursor' => $this->remote_cursor,
        ];
    }
}

class DatabaseTableIndexState
{
    /** @var string|null Path to the db table index file. */
    public ?string $file = null;

    /** @var int Number of tables indexed. */
    public int $tables = 0;

    /** @var int Estimated number of rows across indexed tables. */
    public int $rows_estimated = 0;

    /** @var int Bytes represented by the index. */
    public int $bytes = 0;

    /** @var string|null Timestamp of the latest index update. */
    public ?string $updated_at = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        $state->file = isset($data['file']) ? (string) $data['file'] : null;
        $state->tables = (int) ($data['tables'] ?? 0);
        $state->rows_estimated = (int) ($data['rows_estimated'] ?? 0);
        $state->bytes = (int) ($data['bytes'] ?? 0);
        $state->updated_at = isset($data['updated_at']) ? (string) $data['updated_at'] : null;
        return $state;
    }

    public function to_array(): array
    {
        return [
            'file' => $this->file,
            'tables' => $this->tables,
            'rows_estimated' => $this->rows_estimated,
            'bytes' => $this->bytes,
            'updated_at' => $this->updated_at,
        ];
    }
}

class FileDiffProgressState
{
    /** @var int Offset into the remote index while diffing. */
    public int $remote_offset = 0;

    /** @var int|null Offset into the immutable local-index snapshot while diffing. */
    public ?int $local_offset = null;

    /** @var string|null Legacy path cursor used only to seed local_offset. */
    public ?string $local_after = null;

    /** @var string|null Conflict policy selected for this files-pull lifecycle. */
    public ?string $conflict_policy = null;

    /** @var bool Whether applying the selected conflict policy has started. */
    public bool $conflict_policy_locked = false;

    /**
     * @var bool|null Whether this files-pull lifecycle maintains the previous
     * local index.
     */
    public ?bool $maintain_previous_local_index = null;

    /** @var int Offset into the planned local index while applying the remote diff. */
    public int $planned_local_offset = 0;

    /** @var int Byte offset of the first unread conflict. */
    public int $conflict_offset = 0;

    /** @var int|null Top linked entry for an our-wins subtree being retained. */
    public ?int $retained_local_subtree_top_offset = null;

    /** @var int Durable end offset of the retained-subtree stack. */
    public int $retained_local_subtree_stack_offset = 0;

    /** @var int|null Durable byte offset in the normal download list. */
    public ?int $download_list_offset = null;

    /** @var int|null Durable byte offset in the skipped download list. */
    public ?int $skipped_download_list_offset = null;

    /** @var int|null Top linked entry for a directory pending removal. */
    public ?int $pending_deleted_directory_top_offset = null;

    /** @var int Durable end offset of the pending-directory stack. */
    public int $pending_deleted_directory_stack_offset = 0;

    /**
     * @var array<string,mixed>|null Local deletion saved before its filesystem
     * mutation.
     */
    public ?array $pending_local_action = null;

    /** @var array<string,mixed>|null Cursor for files-pull conflict preparation. */
    public ?array $conflict_processor_cursor = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        $state->remote_offset = (int) ($data['remote_offset'] ?? 0);
        $state->local_offset = isset($data['local_offset'])
            ? (int) $data['local_offset']
            : null;
        $state->local_after = isset($data['local_after']) ? (string) $data['local_after'] : null;
        $state->conflict_policy = isset($data['conflict_policy']) ? (string) $data['conflict_policy'] : null;
        $state->conflict_policy_locked = (bool) ( $data['conflict_policy_locked'] ?? false );
        $state->maintain_previous_local_index =
            isset($data['maintain_previous_local_index'])
                ? (bool) $data['maintain_previous_local_index']
                : null;
        $state->planned_local_offset = (int) ( $data['planned_local_offset'] ?? 0 );
        $state->conflict_offset = (int) ( $data['conflict_offset'] ?? 0 );
        $state->retained_local_subtree_top_offset =
            isset($data['retained_local_subtree_top_offset'])
                ? (int) $data['retained_local_subtree_top_offset']
                : null;
        $state->retained_local_subtree_stack_offset =
            (int) ( $data['retained_local_subtree_stack_offset'] ?? 0 );
        $state->download_list_offset = isset($data['download_list_offset'])
            ? (int) $data['download_list_offset']
            : null;
        $state->skipped_download_list_offset = isset($data['skipped_download_list_offset'])
            ? (int) $data['skipped_download_list_offset']
            : null;
        $state->pending_deleted_directory_top_offset =
            isset($data['pending_deleted_directory_top_offset'])
                ? (int) $data['pending_deleted_directory_top_offset']
                : null;
        $state->pending_deleted_directory_stack_offset =
            (int) ( $data['pending_deleted_directory_stack_offset'] ?? 0 );
        $state->pending_local_action = self::normalize_pending_local_action(
            $data['pending_local_action'] ?? null
        );
        $state->conflict_processor_cursor = self::decode_conflict_processor_cursor(
            $data['conflict_processor_cursor'] ?? null
        );
        return $state;
    }

    public function to_array(): array
    {
        return [
            'remote_offset' => $this->remote_offset,
            'local_offset' => $this->local_offset,
            'local_after' => $this->local_after,
            'conflict_policy' => $this->conflict_policy,
            'conflict_policy_locked' => $this->conflict_policy_locked,
            'maintain_previous_local_index' =>
                $this->maintain_previous_local_index,
            'planned_local_offset' => $this->planned_local_offset,
            'conflict_offset' => $this->conflict_offset,
            'retained_local_subtree_top_offset' =>
                $this->retained_local_subtree_top_offset,
            'retained_local_subtree_stack_offset' =>
                $this->retained_local_subtree_stack_offset,
            'download_list_offset' => $this->download_list_offset,
            'skipped_download_list_offset' => $this->skipped_download_list_offset,
            'pending_deleted_directory_top_offset' =>
                $this->pending_deleted_directory_top_offset,
            'pending_deleted_directory_stack_offset' =>
                $this->pending_deleted_directory_stack_offset,
            'pending_local_action' =>
                self::normalize_pending_local_action(
                    $this->pending_local_action
                ),
            'conflict_processor_cursor' =>
                self::encode_conflict_processor_cursor(
                    $this->conflict_processor_cursor
                ),
        ];
    }

    /** Encodes arbitrary cursor bytes for JSON state persistence. */
    private static function encode_conflict_processor_cursor(
        ?array $cursor
    ): ?array {
        if ($cursor === null) {
            return null;
        }
        return [
            'serialized_b64' => base64_encode(serialize($cursor)),
        ];
    }

    /**
     * Validates one pending local deletion before exposing or persisting it.
     *
     * @return array<string,mixed>|null
     */
    private static function normalize_pending_local_action(
        $value
    ): ?array {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new UnexpectedValueException(
                'The pending files-pull local action must be an object.'
            );
        }
        $keys = array_keys($value);
        sort($keys);
        if (
            $keys
            !== ['accepted_local_state', 'kind', 'path_b64']
        ) {
            throw new UnexpectedValueException(
                'The pending files-pull local action has invalid fields.'
            );
        }
        $kind = $value['kind'];
        if (
            !is_string($kind)
            || !in_array(
                $kind,
                ['delete_path', 'remove_empty_directory'],
                true
            )
        ) {
            throw new UnexpectedValueException(
                'The pending files-pull local action kind is invalid.'
            );
        }
        $path_b64 = $value['path_b64'];
        $path =
            is_string($path_b64)
                ? base64_decode($path_b64, true)
                : false;
        if (
            $path === false
            || $path === ''
            || base64_encode($path) !== $path_b64
        ) {
            throw new UnexpectedValueException(
                'The pending files-pull local action path is not canonical base64.'
            );
        }
        $accepted_local_state = self::normalize_pending_local_state(
            $value['accepted_local_state']
        );
        if (
            $kind === 'remove_empty_directory'
            && $accepted_local_state !== null
            && (
                $accepted_local_state['type'] !== 'dir'
                || !$accepted_local_state['empty']
            )
        ) {
            throw new UnexpectedValueException(
                'A pending empty-directory removal must accept an empty directory.'
            );
        }
        return [
            'kind' => $kind,
            'path_b64' => $path_b64,
            'accepted_local_state' => $accepted_local_state,
        ];
    }

    /**
     * Validates the exact live path state accepted by a pending deletion.
     *
     * @return array<string,mixed>|null
     */
    private static function normalize_pending_local_state(
        $value
    ): ?array {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new UnexpectedValueException(
                'The pending files-pull local path state must be an object or null.'
            );
        }
        $type = $value['type'] ?? null;
        if (
            !is_string($type)
            || !in_array($type, ['file', 'link', 'dir', 'other'], true)
        ) {
            throw new UnexpectedValueException(
                'The pending files-pull local path type is invalid.'
            );
        }
        $expected_keys =
            $type === 'dir'
                ? ['ctime', 'empty', 'size', 'type']
                : ['ctime', 'size', 'type'];
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== $expected_keys) {
            throw new UnexpectedValueException(
                'The pending files-pull local path state has invalid fields.'
            );
        }
        if (
            !is_int($value['ctime'])
            || !is_int($value['size'])
            || $value['size'] < 0
            || ( $type === 'dir' && !is_bool($value['empty']) )
        ) {
            throw new UnexpectedValueException(
                'The pending files-pull local path metadata is invalid.'
            );
        }
        $state = [
            'type' => $type,
            'ctime' => $value['ctime'],
            'size' => $value['size'],
        ];
        if ($type === 'dir') {
            $state['empty'] = $value['empty'];
        }
        return $state;
    }

    /** Decodes a cursor persisted by encode_conflict_processor_cursor(). */
    private static function decode_conflict_processor_cursor(
        $value
    ): ?array {
        if (!is_array($value)) {
            return null;
        }
        if (!isset($value['serialized_b64'])) {
            return $value;
        }
        $serialized = base64_decode(
            (string) $value['serialized_b64'],
            true
        );
        if ($serialized === false) {
            throw new UnexpectedValueException(
                'The files-pull conflict cursor is not valid base64.'
            );
        }
        $cursor = @unserialize(
            $serialized,
            ['allowed_classes' => false]
        );
        if (!is_array($cursor)) {
            throw new UnexpectedValueException(
                'The files-pull conflict cursor is not a serialized array.'
            );
        }
        return $cursor;
    }
}

class RemoteFileIndexCursorState
{
    /** @var string|null Remote file-index cursor. */
    public ?string $cursor = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        $state->cursor = isset($data['cursor']) ? (string) $data['cursor'] : null;
        return $state;
    }

    public function to_array(): array
    {
        return ['cursor' => $this->cursor];
    }
}

class DownloadListFetchProgressState
{
    /** @var int Current byte offset into the download-list file. */
    public int $offset = 0;

    /** @var int Next byte offset after the current batch. */
    public int $next_offset = 0;

    /** @var string|null Path to the current batch file. */
    public ?string $batch_file = null;

    /** @var string|null Cursor returned by the active fetch request. */
    public ?string $cursor = null;

    /** @var int Number of file entries in the current batch. */
    public int $batch_entries = 0;

    /** @var int Offset into the batch's planned-local-state sidecar. */
    public int $planned_local_state_offset = 0;

    /** @var string|null Remote path whose planned state was accepted before mutation. */
    public ?string $applying_path = null;

    /** @var array<string,mixed>|null Planned state for the applying path. */
    public ?array $applying_expected_local_state = null;

    /** @var array<string,mixed>|null Private file receiving a regular-file download. */
    public ?array $staged_file = null;

    /** @var array<string,mixed>|null Completed staged file awaiting installation. */
    public ?array $pending_file_install = null;

    /** @var int|null Top linked entry for an our-wins subtree being retained. */
    public ?int $retained_local_subtree_top_offset = null;

    /** @var int Durable end offset of the retained-subtree stack. */
    public int $retained_local_subtree_stack_offset = 0;

    public static function from_array(array $data): self
    {
        $state = new self();
        $state->offset = (int) ( $data['offset'] ?? 0 );
        $state->next_offset = (int) ($data['next_offset'] ?? 0);
        $state->batch_file = isset($data['batch_file']) ? (string) $data['batch_file'] : null;
        $state->cursor = isset($data['cursor']) ? (string) $data['cursor'] : null;
        $state->batch_entries = (int) ($data['batch_entries'] ?? 0);
        $state->planned_local_state_offset = (int) ( $data['planned_local_state_offset'] ?? 0 );
        $state->applying_path = isset($data['applying_path']) ? (string) $data['applying_path'] : null;
        $state->applying_expected_local_state =
            isset($data['applying_expected_local_state'])
            && is_array($data['applying_expected_local_state'])
                ? $data['applying_expected_local_state']
                : null;
        $state->staged_file = self::normalize_staged_file(
            $data['staged_file'] ?? null,
            false
        );
        $state->pending_file_install = self::normalize_staged_file(
            $data['pending_file_install'] ?? null,
            true
        );
        $state->retained_local_subtree_top_offset =
            isset($data['retained_local_subtree_top_offset'])
                ? (int) $data['retained_local_subtree_top_offset']
                : null;
        $state->retained_local_subtree_stack_offset =
            (int) ( $data['retained_local_subtree_stack_offset'] ?? 0 );
        return $state;
    }

    public function to_array(): array
    {
        return [
            'offset' => $this->offset,
            'next_offset' => $this->next_offset,
            'batch_file' => $this->batch_file,
            'cursor' => $this->cursor,
            'batch_entries' => $this->batch_entries,
            'planned_local_state_offset' => $this->planned_local_state_offset,
            'applying_path' => $this->applying_path,
            'applying_expected_local_state' =>
                $this->applying_expected_local_state,
            'staged_file' =>
                self::normalize_staged_file($this->staged_file, false),
            'pending_file_install' =>
                self::normalize_staged_file(
                    $this->pending_file_install,
                    true
                ),
            'retained_local_subtree_top_offset' =>
                $this->retained_local_subtree_top_offset,
            'retained_local_subtree_stack_offset' =>
                $this->retained_local_subtree_stack_offset,
        ];
    }

    /**
     * Validates a private staged file before exposing or persisting it.
     *
     * @return array<string,mixed>|null
     */
    private static function normalize_staged_file(
        $value,
        bool $pending_install
    ): ?array {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new UnexpectedValueException(
                'The files-pull staged file must be an object.'
            );
        }
        $expected_keys = [
            'destination_path_b64',
            'discard_started',
            'install_mode',
            'remote_ctime',
            'remote_file_changed',
            'remote_path_b64',
            'remote_size',
            'staging_bytes',
            'staging_dev',
            'staging_ino',
            'staging_path_b64',
            'validate_local_state',
        ];
        if ($pending_install) {
            $expected_keys[] = 'cursor';
            $expected_keys[] = 'destination_removal';
            $expected_keys[] = 'installed_ctime';
            $expected_keys[] = 'planned_local_state_offset';
        }
        sort($expected_keys);
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== $expected_keys) {
            throw new UnexpectedValueException(
                'The files-pull staged file has invalid fields.'
            );
        }
        foreach (
            [
                'remote_path_b64',
                'destination_path_b64',
                'staging_path_b64',
            ] as $path_key
        ) {
            $encoded_path = $value[$path_key];
            $path = is_string($encoded_path)
                ? base64_decode($encoded_path, true)
                : false;
            if (
                $path === false
                || $path === ''
                || base64_encode($path) !== $encoded_path
            ) {
                throw new UnexpectedValueException(
                    'A files-pull staged-file path is not canonical base64.'
                );
            }
        }
        foreach (
            [
                'remote_ctime',
                'remote_size',
                'staging_bytes',
                'install_mode',
            ] as $integer_key
        ) {
            if (
                !is_int($value[$integer_key])
                || $value[$integer_key] < 0
                || (
                    $integer_key === 'install_mode'
                    && $value[$integer_key] > 07777
                )
            ) {
                throw new UnexpectedValueException(
                    'The files-pull staged-file byte metadata is invalid.'
                );
            }
        }
        foreach (['staging_dev', 'staging_ino'] as $identity_key) {
            if (
                $value[$identity_key] !== null
                && (
                    !is_int($value[$identity_key])
                    || $value[$identity_key] < 0
                )
            ) {
                throw new UnexpectedValueException(
                    'The files-pull staged-file identity is invalid.'
                );
            }
        }
        $staging_dev_is_missing = $value['staging_dev'] === null;
        $staging_ino_is_missing = $value['staging_ino'] === null;
        if ($staging_dev_is_missing !== $staging_ino_is_missing) {
            throw new UnexpectedValueException(
                'The files-pull staged-file identity is incomplete.'
            );
        }
        if (!is_bool($value['remote_file_changed'])) {
            throw new UnexpectedValueException(
                'The files-pull staged-file change marker is invalid.'
            );
        }
        if (!is_bool($value['discard_started'])) {
            throw new UnexpectedValueException(
                'The files-pull staged-file discard marker is invalid.'
            );
        }
        if (!is_bool($value['validate_local_state'])) {
            throw new UnexpectedValueException(
                'The files-pull staged-file validation marker is invalid.'
            );
        }
        $normalized = [
            'remote_path_b64' => $value['remote_path_b64'],
            'destination_path_b64' =>
                $value['destination_path_b64'],
            'staging_path_b64' => $value['staging_path_b64'],
            'staging_dev' => $value['staging_dev'],
            'staging_ino' => $value['staging_ino'],
            'staging_bytes' => $value['staging_bytes'],
            'install_mode' => $value['install_mode'],
            'remote_ctime' => $value['remote_ctime'],
            'remote_size' => $value['remote_size'],
            'remote_file_changed' =>
                $value['remote_file_changed'],
            'discard_started' => $value['discard_started'],
            'validate_local_state' =>
                $value['validate_local_state'],
        ];
        if ($pending_install) {
            if (
                $value['staging_dev'] === null
                || $value['staging_ino'] === null
            ) {
                throw new UnexpectedValueException(
                    'A pending files-pull install needs a staging identity.'
                );
            }
            if (
                $value['cursor'] !== null
                && !is_string($value['cursor'])
            ) {
                throw new UnexpectedValueException(
                    'The pending files-pull file cursor is invalid.'
                );
            }
            if (
                !is_int($value['planned_local_state_offset'])
                || $value['planned_local_state_offset'] < 0
            ) {
                throw new UnexpectedValueException(
                    'The pending files-pull planned-state offset is invalid.'
                );
            }
            $normalized['cursor'] = $value['cursor'];
            $destination_removal = $value['destination_removal'];
            $destination_removal_keys =
                is_array($destination_removal)
                    ? array_keys($destination_removal)
                    : [];
            sort($destination_removal_keys);
            $quarantine_path_b64 =
                is_array($destination_removal)
                && is_string(
                    $destination_removal['quarantine_path_b64'] ?? null
                )
                    ? $destination_removal['quarantine_path_b64']
                    : null;
            $quarantine_path =
                $quarantine_path_b64 === null
                    ? false
                    : base64_decode($quarantine_path_b64, true);
            if (
                $destination_removal !== null
                && (
                    !is_array($destination_removal)
                    || $destination_removal_keys
                        !== [
                            'directory_dev',
                            'directory_ino',
                            'quarantine_path_b64',
                            'stack_offset',
                            'top_offset',
                        ]
                    || $quarantine_path === false
                    || $quarantine_path === ''
                    || base64_encode($quarantine_path)
                        !== $quarantine_path_b64
                    || !is_int($destination_removal['directory_dev'])
                    || $destination_removal['directory_dev'] < 0
                    || !is_int($destination_removal['directory_ino'])
                    || $destination_removal['directory_ino'] < 0
                    || (
                        $destination_removal['top_offset'] !== null
                        && (
                            !is_int(
                                $destination_removal['top_offset']
                            )
                            || $destination_removal['top_offset'] < 0
                        )
                    )
                    || !is_int(
                        $destination_removal['stack_offset']
                    )
                    || $destination_removal['stack_offset'] < 0
                )
            ) {
                throw new UnexpectedValueException(
                    'The pending files-pull destination-removal state is invalid.'
                );
            }
            $normalized['destination_removal'] =
                $destination_removal;
            if (
                $value['installed_ctime'] !== null
                && (
                    !is_int($value['installed_ctime'])
                    || $value['installed_ctime'] < 0
                )
            ) {
                throw new UnexpectedValueException(
                    'The pending files-pull installed ctime is invalid.'
                );
            }
            $normalized['installed_ctime'] =
                $value['installed_ctime'];
            $normalized['planned_local_state_offset'] =
                $value['planned_local_state_offset'];
        }
        return $normalized;
    }
}

class FilesPullSummaryState
{
    /** @var int Number of changed files pulled in the current files-pull run. */
    public int $files_pulled = 0;

    public static function from_array(array $data): self
    {
        $state = new self();
        $state->files_pulled = (int) ($data['files_pulled'] ?? 0);
        return $state;
    }

    public function to_array(): array
    {
        return ['files_pulled' => $this->files_pulled];
    }
}

class DatabaseApplyCommandState
{
    /** @var int SQL statements successfully executed. */
    public int $statements_executed = 0;

    /** @var int Bytes read from db.sql. */
    public int $bytes_read = 0;

    /** @var array<string,string>|null URL rewrite map selected for db-apply. */
    public ?array $rewrite_url = null;

    /** @var string|null Runtime target database engine: mysql or sqlite. */
    public ?string $target_engine = null;

    /** @var string|null Runtime database name. */
    public ?string $target_db = null;

    /** @var string|null Runtime database host. */
    public ?string $target_host = null;

    /** @var int|null Runtime database port. */
    public ?int $target_port = null;

    /** @var string|null Runtime database user. */
    public ?string $target_user = null;

    /** @var string|null Runtime database password. */
    public ?string $target_pass = null;

    /** @var string|null Runtime SQLite database path. */
    public ?string $target_sqlite_path = null;

    /** @var string[] Remote paths intentionally removed while applying runtime state. */
    public array $remote_paths_removed_from_local_site = [];

    public static function from_array(array $data): self
    {
        $state = new self();
        $state->statements_executed = (int) ($data['statements_executed'] ?? 0);
        $state->bytes_read = (int) ($data['bytes_read'] ?? 0);
        $state->rewrite_url = isset($data['rewrite_url']) && is_array($data['rewrite_url']) ? $data['rewrite_url'] : null;
        $state->target_engine = isset($data['target_engine']) ? (string) $data['target_engine'] : null;
        $state->target_db = isset($data['target_db']) ? (string) $data['target_db'] : null;
        $state->target_host = isset($data['target_host']) ? (string) $data['target_host'] : null;
        $state->target_port = isset($data['target_port']) ? (int) $data['target_port'] : null;
        $state->target_user = isset($data['target_user']) ? (string) $data['target_user'] : null;
        $state->target_pass = isset($data['target_pass']) ? (string) $data['target_pass'] : null;
        $state->target_sqlite_path = isset($data['target_sqlite_path']) ? (string) $data['target_sqlite_path'] : null;
        $state->remote_paths_removed_from_local_site = isset($data['remote_paths_removed_from_local_site']) && is_array($data['remote_paths_removed_from_local_site'])
            ? array_values($data['remote_paths_removed_from_local_site'])
            : [];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'statements_executed' => $this->statements_executed,
            'bytes_read' => $this->bytes_read,
            'rewrite_url' => $this->rewrite_url,
            'target_engine' => $this->target_engine,
            'target_db' => $this->target_db,
            'target_host' => $this->target_host,
            'target_port' => $this->target_port,
            'target_user' => $this->target_user,
            'target_pass' => $this->target_pass,
            'target_sqlite_path' => $this->target_sqlite_path,
            'remote_paths_removed_from_local_site' => $this->remote_paths_removed_from_local_site,
        ];
    }
}

class AdaptiveTuningState
{
    /** @var array<string,mixed> Tuner configuration. */
    public array $config = [];

    /** @var array<string,mixed> Tuner runtime state. */
    public array $state = [];

    public static function from_array(array $data): self
    {
        $state = new self();
        $state->config = isset($data['config']) && is_array($data['config']) ? $data['config'] : [];
        $state->state = isset($data['state']) && is_array($data['state']) ? $data['state'] : [];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'config' => $this->config,
            'state' => $this->state,
        ];
    }
}

class PullPipelineCheckpointState
{
    /** @var string|null User-facing pipeline command that owns the checkpoint. */
    public ?string $started_by_command = null;

    /** @var string[] Ordered stage names for the pipeline currently being resumed. */
    public array $stage_sequence = [];

    /** @var string|null Last whole pipeline stage saved as complete. */
    public ?string $last_completed_stage = null;

    /** @var string|null Files filter used by the pipeline. */
    public ?string $files_filter = null;

    /** @var bool Whether deferred files are still pending. */
    public bool $skipped_pending = false;

    /** @var bool Whether this pipeline completed at least once. */
    public bool $has_completed_once = false;

    public static function from_array(array $data): self
    {
        $state = new self();
        $state->started_by_command = isset($data['started_by_command']) ? (string) $data['started_by_command'] : null;
        $state->stage_sequence = isset($data['stage_sequence']) && is_array($data['stage_sequence'])
            ? array_values($data['stage_sequence'])
            : [];
        $state->last_completed_stage = isset($data['last_completed_stage']) ? (string) $data['last_completed_stage'] : null;
        $state->files_filter = isset($data['files_filter']) ? (string) $data['files_filter'] : null;
        $state->skipped_pending = (bool) ($data['skipped_pending'] ?? false);
        $state->has_completed_once = (bool) ($data['has_completed_once'] ?? false);
        return $state;
    }

    public function to_array(): array
    {
        return [
            'started_by_command' => $this->started_by_command,
            'stage_sequence' => $this->stage_sequence,
            'last_completed_stage' => $this->last_completed_stage,
            'files_filter' => $this->files_filter,
            'skipped_pending' => $this->skipped_pending,
            'has_completed_once' => $this->has_completed_once,
        ];
    }
}

/**
 * In-process import state with typed properties for each persisted field.
 *
 * This object mirrors .import-state.json. Add new persistent state here first;
 * from_array() accepts missing legacy fields and to_array() keeps the JSON
 * schema stable for existing installations.
 */
class ImportState
{
    public ResumableCommandCheckpointState $active_resumable_command;
    /** @var array<string,mixed>|null */
    public ?array $preflight = null;
    public ?int $remote_protocol_version = null;
    public ?int $remote_protocol_min_version = null;
    /** @var string|null Importer version saved with state. */
    public ?string $version = null;
    /** @var string|null Webhost detected during preflight. */
    public ?string $webhost = null;
    public bool $follow_symlinks = true;
    /** @var string|null Fingerprint of the --follow-symlinks bundle directory; guards resume. */
    public ?string $symlink_bundle_directory_fingerprint = null;
    public string $fs_root_nonempty_behavior = 'error';
    public string $filter = 'none';
    /** @var string|null User-Agent that worked during preflight. */
    public ?string $user_agent = null;
    public ?int $max_allowed_packet = null;
    public ?string $files_remap_fingerprint = null;
    public ?string $files_pull_only_fingerprint = null;
    /** @var int Private regular-file staging schema used by files-pull. */
    public int $files_pull_staging_version = 0;
    public FilesPullSummaryState $files_pull_summary;
    public DatabaseTableIndexState $db_index;
    public FileDiffProgressState $diff;
    public RemoteFileIndexCursorState $index;
    public DownloadListFetchProgressState $fetch;
    public DownloadListFetchProgressState $fetch_skipped;
    public ?int $sql_bytes = null;
    /** @var int SQL statements counted while streaming db.sql. */
    public int $sql_statements_counted = 0;
    public DatabaseApplyCommandState $apply;
    public ?string $sql_output = null;
    public ?string $mysql_host = null;
    public ?int $mysql_port = null;
    public ?string $mysql_user = null;
    public ?string $mysql_database = null;
    public int $consecutive_timeouts = 0;
    public AdaptiveTuningState $tuning;
    public PullPipelineCheckpointState $pull_pipeline;

    public function __construct()
    {
        $this->active_resumable_command = new ResumableCommandCheckpointState();
        $this->db_index = new DatabaseTableIndexState();
        $this->diff = new FileDiffProgressState();
        $this->index = new RemoteFileIndexCursorState();
        $this->fetch = new DownloadListFetchProgressState();
        $this->fetch_skipped = new DownloadListFetchProgressState();
        $this->files_pull_summary = new FilesPullSummaryState();
        $this->apply = new DatabaseApplyCommandState();
        $this->tuning = new AdaptiveTuningState();
        $this->pull_pipeline = new PullPipelineCheckpointState();
    }

    public static function from_array(array $data): self
    {
        $state = new self();
        $state->active_resumable_command = self::resumable_command_checkpoint_from($data['active_resumable_command'] ?? []);
        $state->preflight = isset($data['preflight']) && is_array($data['preflight']) ? $data['preflight'] : null;
        $state->remote_protocol_version = isset($data['remote_protocol_version']) ? (int) $data['remote_protocol_version'] : null;
        $state->remote_protocol_min_version = isset($data['remote_protocol_min_version']) ? (int) $data['remote_protocol_min_version'] : null;
        $state->version = isset($data['version']) ? (string) $data['version'] : null;
        $state->webhost = isset($data['webhost']) ? (string) $data['webhost'] : null;
        $state->follow_symlinks = (bool) ($data['follow_symlinks'] ?? true);
        $state->symlink_bundle_directory_fingerprint = isset($data['symlink_bundle_directory_fingerprint']) ? (string) $data['symlink_bundle_directory_fingerprint'] : null;
        $state->fs_root_nonempty_behavior = isset($data['fs_root_nonempty_behavior']) ? (string) $data['fs_root_nonempty_behavior'] : 'error';
        $state->filter = isset($data['filter']) ? (string) $data['filter'] : 'none';
        $state->user_agent = isset($data['user_agent']) ? (string) $data['user_agent'] : null;
        $state->max_allowed_packet = isset($data['max_allowed_packet']) ? (int) $data['max_allowed_packet'] : null;
        $state->files_remap_fingerprint = isset($data['files_remap_fingerprint']) ? (string) $data['files_remap_fingerprint'] : null;
        $state->files_pull_only_fingerprint = isset($data['files_pull_only_fingerprint']) ? (string) $data['files_pull_only_fingerprint'] : null;
        $state->files_pull_staging_version =
            (int) ( $data['files_pull_staging_version'] ?? 0 );
        $state->files_pull_summary = self::files_pull_summary_from($data['files_pull_summary'] ?? []);
        $state->db_index = self::database_table_index_from($data['db_index'] ?? []);
        $state->diff = self::file_diff_progress_from($data['diff'] ?? []);
        $state->index = self::remote_file_index_cursor_from($data['index'] ?? []);
        $state->fetch = self::download_list_fetch_progress_from($data['fetch'] ?? []);
        $state->fetch_skipped = self::download_list_fetch_progress_from($data['fetch_skipped'] ?? []);
        $state->sql_bytes = isset($data['sql_bytes']) ? (int) $data['sql_bytes'] : null;
        $state->sql_statements_counted = (int) ($data['sql_statements_counted'] ?? 0);
        $state->apply = self::database_apply_command_from($data['apply'] ?? []);
        $state->sql_output = isset($data['sql_output']) ? (string) $data['sql_output'] : null;
        $state->mysql_host = isset($data['mysql_host']) ? (string) $data['mysql_host'] : null;
        $state->mysql_port = isset($data['mysql_port']) ? (int) $data['mysql_port'] : null;
        $state->mysql_user = isset($data['mysql_user']) ? (string) $data['mysql_user'] : null;
        $state->mysql_database = isset($data['mysql_database']) ? (string) $data['mysql_database'] : null;
        $state->consecutive_timeouts = (int) ($data['consecutive_timeouts'] ?? 0);
        $state->tuning = self::adaptive_tuning_from($data['tuning'] ?? []);
        $state->pull_pipeline = self::pull_pipeline_checkpoint_from($data['pull_pipeline'] ?? []);
        return $state;
    }

    public function to_array(): array
    {
        return [
            'active_resumable_command' => $this->active_resumable_command->to_array(),
            'preflight' => $this->preflight,
            'remote_protocol_version' => $this->remote_protocol_version,
            'remote_protocol_min_version' => $this->remote_protocol_min_version,
            'version' => $this->version,
            'webhost' => $this->webhost,
            'follow_symlinks' => $this->follow_symlinks,
            'symlink_bundle_directory_fingerprint' => $this->symlink_bundle_directory_fingerprint,
            'fs_root_nonempty_behavior' => $this->fs_root_nonempty_behavior,
            'filter' => $this->filter,
            'user_agent' => $this->user_agent,
            'max_allowed_packet' => $this->max_allowed_packet,
            'files_remap_fingerprint' => $this->files_remap_fingerprint,
            'files_pull_only_fingerprint' => $this->files_pull_only_fingerprint,
            'files_pull_staging_version' =>
                $this->files_pull_staging_version,
            'files_pull_summary' => $this->files_pull_summary->to_array(),
            'db_index' => $this->db_index->to_array(),
            'diff' => $this->diff->to_array(),
            'index' => $this->index->to_array(),
            'fetch' => $this->fetch->to_array(),
            'fetch_skipped' => $this->fetch_skipped->to_array(),
            'sql_bytes' => $this->sql_bytes,
            'sql_statements_counted' => $this->sql_statements_counted,
            'apply' => $this->apply->to_array(),
            'sql_output' => $this->sql_output,
            'mysql_host' => $this->mysql_host,
            'mysql_port' => $this->mysql_port,
            'mysql_user' => $this->mysql_user,
            'mysql_database' => $this->mysql_database,
            'consecutive_timeouts' => $this->consecutive_timeouts,
            'tuning' => $this->tuning->to_array(),
            'pull_pipeline' => $this->pull_pipeline->to_array(),
        ];
    }

    private static function resumable_command_checkpoint_from($value): ResumableCommandCheckpointState
    {
        return $value instanceof ResumableCommandCheckpointState ? $value : ResumableCommandCheckpointState::from_array(is_array($value) ? $value : []);
    }

    private static function files_pull_summary_from($value): FilesPullSummaryState
    {
        return $value instanceof FilesPullSummaryState ? $value : FilesPullSummaryState::from_array(is_array($value) ? $value : []);
    }

    private static function database_table_index_from($value): DatabaseTableIndexState
    {
        return $value instanceof DatabaseTableIndexState ? $value : DatabaseTableIndexState::from_array(is_array($value) ? $value : []);
    }

    private static function file_diff_progress_from($value): FileDiffProgressState
    {
        return $value instanceof FileDiffProgressState ? $value : FileDiffProgressState::from_array(is_array($value) ? $value : []);
    }

    private static function remote_file_index_cursor_from($value): RemoteFileIndexCursorState
    {
        return $value instanceof RemoteFileIndexCursorState ? $value : RemoteFileIndexCursorState::from_array(is_array($value) ? $value : []);
    }

    private static function download_list_fetch_progress_from($value): DownloadListFetchProgressState
    {
        return $value instanceof DownloadListFetchProgressState ? $value : DownloadListFetchProgressState::from_array(is_array($value) ? $value : []);
    }

    private static function database_apply_command_from($value): DatabaseApplyCommandState
    {
        return $value instanceof DatabaseApplyCommandState ? $value : DatabaseApplyCommandState::from_array(is_array($value) ? $value : []);
    }

    private static function adaptive_tuning_from($value): AdaptiveTuningState
    {
        return $value instanceof AdaptiveTuningState ? $value : AdaptiveTuningState::from_array(is_array($value) ? $value : []);
    }

    private static function pull_pipeline_checkpoint_from($value): PullPipelineCheckpointState
    {
        return $value instanceof PullPipelineCheckpointState ? $value : PullPipelineCheckpointState::from_array(is_array($value) ? $value : []);
    }
}
