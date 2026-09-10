<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace ImportTests;

class InterruptingDatabaseUrlRewriteClient extends \ImportClient {

    private int $stop_after_records;
    private bool $stop_requested = false;

    public function __construct(
        string $remote_reprint_api_url,
        string $state_dir,
        string $filesystem_root,
        int $stop_after_records
    ) {
        parent::__construct($remote_reprint_api_url, $state_dir, $filesystem_root);
        $this->stop_after_records = $stop_after_records;
    }

    public function save_state(): void
    {
        parent::save_state();

        if (
            !$this->stop_requested
            && $this->get_state()->database_url_rewrite->records_processed >= $this->stop_after_records
        ) {
            $this->handle_database_url_rewrite_shutdown(SIGINT);
            $this->stop_requested = true;
        }
    }
}
