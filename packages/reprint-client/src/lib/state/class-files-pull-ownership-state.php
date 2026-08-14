<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- State errors contain schema fields, never HTML.

/** Retains files-pull ownership snapshot IDs and one active processor cursor. */
class FilesPullOwnershipState {
    /** @var array<string,list<string>> Snapshot IDs keyed by path-selection fingerprint. */
    public array $committed_snapshot_ids_by_selection_fingerprint = [];
    public ?string $active_snapshot_id = null;
    /** @var array|null Opaque FilesPullOwnershipProcessor cursor. */
    public ?array $processor_cursor = null;
    /** @var list<string> */
    public array $snapshot_ids_pending_removal = [];

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        if (!is_array($data['committed_snapshot_ids_by_selection_fingerprint'])) {
            throw new \UnexpectedValueException(self::class . ' committed snapshots must be an object.');
        }
        foreach ($data['committed_snapshot_ids_by_selection_fingerprint'] as $selection_fingerprint => $snapshot_ids) {
            self::assert_identifier($selection_fingerprint, 'path-selection fingerprint');
            self::assert_snapshot_id_list($snapshot_ids, 'committed snapshot IDs');
        }
        $state->committed_snapshot_ids_by_selection_fingerprint = $data['committed_snapshot_ids_by_selection_fingerprint'];
        ksort($state->committed_snapshot_ids_by_selection_fingerprint, SORT_STRING);

        if ($data['active_snapshot_id'] !== null) {
            self::assert_identifier($data['active_snapshot_id'], 'active snapshot ID');
        }
        if ($data['processor_cursor'] !== null && !is_array($data['processor_cursor'])) {
            throw new \UnexpectedValueException(self::class . ' processor cursor must be an array or null.');
        }
        if ($data['active_snapshot_id'] !== null && $data['processor_cursor'] !== null) {
            throw new \UnexpectedValueException(self::class . ' cannot retain a snapshot and processor cursor together.');
        }
        self::assert_snapshot_id_list($data['snapshot_ids_pending_removal'], 'snapshot IDs pending removal');
        $state->active_snapshot_id = $data['active_snapshot_id'];
        $state->processor_cursor = $data['processor_cursor'];
        $state->snapshot_ids_pending_removal = $data['snapshot_ids_pending_removal'];
        foreach ($state->snapshot_ids_pending_removal as $snapshot_id) {
            if ($state->references_snapshot($snapshot_id)) {
                throw new \UnexpectedValueException(self::class . ' cannot remove a referenced snapshot.');
            }
        }
        return $state;
    }

    /** Moves the processor's ID to active after both snapshot artifacts exist. */
    public function complete_processor(string $snapshot_id): void
    {
        self::assert_identifier($snapshot_id, 'active snapshot ID');
        if (
            $this->processor_cursor === null
            || $this->active_snapshot_id !== null
        ) {
            throw new \LogicException('Files-pull ownership has no processor to complete.');
        }
        $this->processor_cursor = null;
        $this->active_snapshot_id = $snapshot_id;
        $this->snapshot_ids_pending_removal = array_values(array_diff($this->snapshot_ids_pending_removal, [$snapshot_id]));
    }

    /** Replaces only the successful selection's committed ownership. */
    public function commit_active_snapshot(string $selection_fingerprint): void
    {
        self::assert_identifier($selection_fingerprint, 'path-selection fingerprint');
        if ($this->active_snapshot_id === null) {
            throw new \LogicException('Files-pull ownership has no snapshot to commit.');
        }
        $replaced_snapshot_ids = $this->committed_snapshot_ids_by_selection_fingerprint[$selection_fingerprint] ?? [];
        $this->committed_snapshot_ids_by_selection_fingerprint[$selection_fingerprint] = [$this->active_snapshot_id];
        ksort($this->committed_snapshot_ids_by_selection_fingerprint, SORT_STRING);
        $this->active_snapshot_id = null;
        $this->queue_unreferenced_snapshots($replaced_snapshot_ids);
    }

    /** Retains a candidate at diff or later, and discards it before diff. */
    public function abort_active_snapshot(
        string $selection_fingerprint,
        bool $diff_or_later,
        ?string $processor_snapshot_id
    ): void {
        self::assert_identifier($selection_fingerprint, 'path-selection fingerprint');
        if ($processor_snapshot_id !== null) {
            if ($this->processor_cursor === null) {
                throw new \LogicException('Files-pull ownership has no processor snapshot to abort.');
            }
            self::assert_identifier($processor_snapshot_id, 'processor snapshot ID');
            $this->queue_unreferenced_snapshots([$processor_snapshot_id]);
        }
        $this->processor_cursor = null;
        if ($this->active_snapshot_id === null) {
            return;
        }
        $active_snapshot_id = $this->active_snapshot_id;
        $this->active_snapshot_id = null;
        if ($diff_or_later) {
            $snapshot_ids = $this->committed_snapshot_ids_by_selection_fingerprint[$selection_fingerprint] ?? [];
            $snapshot_ids[] = $active_snapshot_id;
            sort($snapshot_ids, SORT_STRING);
            $this->committed_snapshot_ids_by_selection_fingerprint[$selection_fingerprint] = array_values(array_unique($snapshot_ids));
            ksort($this->committed_snapshot_ids_by_selection_fingerprint, SORT_STRING);
            $this->snapshot_ids_pending_removal = array_values(array_diff($this->snapshot_ids_pending_removal, [$active_snapshot_id]));
            return;
        }
        $this->queue_unreferenced_snapshots([$active_snapshot_id]);
    }

    public function next_snapshot_id_pending_removal(): ?string
    {
        return $this->snapshot_ids_pending_removal === []
            ? null
            : $this->snapshot_ids_pending_removal[count($this->snapshot_ids_pending_removal) - 1];
    }

    /** Confirms the one pending snapshot artifact pair removed by the caller. */
    public function confirm_snapshot_removed(string $snapshot_id): void
    {
        if ($this->next_snapshot_id_pending_removal() !== $snapshot_id) {
            throw new \LogicException('Files-pull ownership did not schedule that snapshot next.');
        }
        array_pop($this->snapshot_ids_pending_removal);
    }

    public function to_array(): array
    {
        return [
            'committed_snapshot_ids_by_selection_fingerprint' => $this->committed_snapshot_ids_by_selection_fingerprint,
            'active_snapshot_id' => $this->active_snapshot_id,
            'processor_cursor' => $this->processor_cursor,
            'snapshot_ids_pending_removal' => $this->snapshot_ids_pending_removal,
        ];
    }

    /** Adds only IDs no longer referenced by a selection or active snapshot. */
    private function queue_unreferenced_snapshots(array $snapshot_ids): void
    {
        foreach ($snapshot_ids as $snapshot_id) {
            self::assert_identifier($snapshot_id, 'snapshot ID');
            if (!$this->references_snapshot($snapshot_id)) {
                $this->snapshot_ids_pending_removal[] = $snapshot_id;
            }
        }
        sort($this->snapshot_ids_pending_removal, SORT_STRING);
        $this->snapshot_ids_pending_removal = array_values(array_unique($this->snapshot_ids_pending_removal));
    }

    private function references_snapshot(string $snapshot_id): bool
    {
        if ($this->active_snapshot_id === $snapshot_id) {
            return true;
        }
        foreach ($this->committed_snapshot_ids_by_selection_fingerprint as $snapshot_ids) {
            if (in_array($snapshot_id, $snapshot_ids, true)) {
                return true;
            }
        }
        return false;
    }

    private static function assert_snapshot_id_list($snapshot_ids, string $name): void
    {
        if (!is_array($snapshot_ids) || array_values($snapshot_ids) !== $snapshot_ids) {
            throw new \UnexpectedValueException(self::class . " {$name} must be a list.");
        }
        foreach ($snapshot_ids as $snapshot_id) {
            self::assert_identifier($snapshot_id, $name);
        }
        $sorted_snapshot_ids = $snapshot_ids;
        sort($sorted_snapshot_ids, SORT_STRING);
        if (
            $snapshot_ids !== $sorted_snapshot_ids
            || count($snapshot_ids) !== count(array_unique($snapshot_ids))
        ) {
            throw new \UnexpectedValueException(self::class . " {$name} must be byte-sorted with no duplicates.");
        }
    }

    private static function assert_identifier($identifier, string $name): void
    {
        if (!is_string($identifier) || preg_match('/^[0-9a-f]{64}$/D', $identifier) !== 1) {
            throw new \UnexpectedValueException(self::class . " {$name} must be 64 lowercase hexadecimal characters.");
        }
    }
}
