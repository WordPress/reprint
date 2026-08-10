<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class AdaptiveTuningState {

    /** @var array<string,mixed> Tuner configuration. */
    public array $config = [];

    /** @var array<string,mixed> Tuner runtime state. */
    public array $state = [];

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->config = $data['config'];
        $state->state = $data['state'];
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
