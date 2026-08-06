<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class ResumableCommandCheckpointState {

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
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->command_name = $data['command_name'];
        $state->completion_state = $data['completion_state'];
        $state->current_stage = $data['current_stage'];
        $state->remote_cursor = $data['remote_cursor'];
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
