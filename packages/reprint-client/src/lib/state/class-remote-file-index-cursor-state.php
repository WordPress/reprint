<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class RemoteFileIndexCursorState {

    /** @var string|null Remote file-index cursor. */
    public ?string $cursor = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->cursor = $data['cursor'];
        return $state;
    }

    public function to_array(): array
    {
        return ['cursor' => $this->cursor];
    }
}
