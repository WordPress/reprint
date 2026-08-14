<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
final class LegacySqliteMetadataDriver {
    /** @var string[] */
    public $queries = [];

    /** @var object[] */
    private $query_results = [];

    /** @return bool */
    public function query(string $query)
    {
        $this->queries[] = $query;
        if ($query === 'SHOW FULL TABLES') {
            $this->query_results = [];
            return false;
        }
        if ($query === 'SHOW TABLES') {
            $this->query_results = [ (object) ['Tables_in_wp_test' => 'wp_posts'] ];
            return true;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        throw new RuntimeException("Unexpected query: {$query}");
    }

    /** @return object[] */
    public function get_query_results(): array
    {
        return $this->query_results;
    }
}
