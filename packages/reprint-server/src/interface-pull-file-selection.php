<?php

namespace WordPress\Reprint\Server;

/** Source-side rules shared by the file index and file download. */
interface PullFileSelection {
    public function get_identity(): string;

    public function includes_path(string $remote_absolute_path): bool;

    public function assert_path_allowed(string $remote_absolute_path): void;
}
