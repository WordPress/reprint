<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class PullPipelineCheckpointState {

    /** @var string|null User-facing pipeline command that owns the checkpoint. */
    public ?string $started_by_command = null;

    /** @var string[] Ordered stage names for the pipeline currently being resumed. */
    public array $stage_sequence = [];

    /** @var string|null Last whole pipeline stage saved as complete. */
    public ?string $last_completed_stage = null;

    /** @var bool Whether this pipeline completed at least once. */
    public bool $has_completed_once = false;

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->started_by_command = $data['started_by_command'];
        $state->stage_sequence = array_values($data['stage_sequence']);
        $state->last_completed_stage = $data['last_completed_stage'];
        $state->has_completed_once = $data['has_completed_once'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'started_by_command' => $this->started_by_command,
            'stage_sequence' => $this->stage_sequence,
            'last_completed_stage' => $this->last_completed_stage,
            'has_completed_once' => $this->has_completed_once,
        ];
    }
}
