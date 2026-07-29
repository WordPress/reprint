<?php
declare(strict_types=1);

namespace Reprint\Importer\State;

class FilesPullSummaryState {

    /** @var int Number of changed files pulled in the current files-pull run. */
    public int $files_pulled = 0;

    public static function from_array(array $data): self
    {
        $state = new self();
        \reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->files_pulled = $data['files_pulled'];
        return $state;
    }

    public function to_array(): array
    {
        return ['files_pulled' => $this->files_pulled];
    }
}
