<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
final class DatabaseRowsReaderMetadataStatement {
    /** @var array[] */
    private $rows;

    /** @param array[] $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /** @return array|false */
    public function fetch()
    {
        if (count($this->rows) === 0) {
            return false;
        }
        return array_shift($this->rows);
    }
}
