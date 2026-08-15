<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

/** Durable progress for db-rewrite-urls. */
class DatabaseUrlRewriteCommandState {

    /** @var string|null DatabaseUrlRewriteProcessor cursor after the last completed step. */
    public ?string $cursor = null;

    /** @var int Database records whose rewrite decision is complete. */
    public int $records_processed = 0;

    /** @var int Database records changed by this lifecycle. */
    public int $records_changed = 0;

    /** @var int Database records whose update result must be verified. */
    public int $records_to_verify = 0;

    /** @var int Tables in which at least one record was processed. */
    public int $tables_started = 0;

    /** @var string|null Table containing the last processed record. */
    public ?string $current_table = null;

    /** @var array<string,string>|null URL rewrite map fixed for this lifecycle. */
    public ?array $rewrite_url = null;

    /** @var array<string,mixed>|null Database identity fixed for this lifecycle. */
    public ?array $target = null;

    /** @var string|null Identifier used in this lifecycle's review report filename. */
    public ?string $review_job_id = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        // State written before review reports were introduced has neither key.
        $data += [
            'records_to_verify' => 0,
            'review_job_id' => null,
        ];
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->cursor = $data['cursor'];
        $state->records_processed = $data['records_processed'];
        $state->records_changed = $data['records_changed'];
        $state->records_to_verify = $data['records_to_verify'];
        $state->tables_started = $data['tables_started'];
        $state->current_table = $data['current_table'];
        $state->rewrite_url = $data['rewrite_url'];
        $state->target = $data['target'];
        $state->review_job_id = $data['review_job_id'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'cursor' => $this->cursor,
            'records_processed' => $this->records_processed,
            'records_changed' => $this->records_changed,
            'records_to_verify' => $this->records_to_verify,
            'tables_started' => $this->tables_started,
            'current_table' => $this->current_table,
            'rewrite_url' => $this->rewrite_url,
            'target' => $this->target,
            'review_job_id' => $this->review_job_id,
        ];
    }
}
