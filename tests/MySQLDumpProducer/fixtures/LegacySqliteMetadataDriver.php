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
        if ($query === 'SHOW TABLE STATUS;') {
            $this->query_results = [ (object) ['Name' => 'wp_posts', 'Engine' => 'myisam'] ];
            return true;
        }
        if ($query === 'SHOW INDEX FROM `wp_posts`' || $query === 'SHOW FULL COLUMNS FROM `wp_posts`') {
            $this->query_results = [];
            return [];
        }
        if ($query === 'SHOW INDEX FROM wp_posts') {
            $this->query_results = [
                (object) [
                    'Key_name' => 'PRIMARY',
                    'Column_name' => 'ID',
                    'Seq_in_index' => 0,
                ],
            ];
            return $this->query_results;
        }
        if ($query === 'SHOW FULL COLUMNS FROM wp_posts') {
            $this->query_results = [ (object) ['Field' => 'ID', 'Type' => 'INTEGER'] ];
            return $this->query_results;
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
