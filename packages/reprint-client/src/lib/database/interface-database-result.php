<?php

declare(strict_types=1);

namespace Reprint\Importer\Database;

use PDO;

/** Result rows returned by a target database query. */
interface DatabaseResult {

    /** Returns the next row, or false after the last row. */
    public function fetch(int $mode = PDO::FETCH_BOTH);

    /** Returns every remaining row. */
    public function fetchAll(int $mode = PDO::FETCH_BOTH): array;

    /** Returns one column from the next row, or false after the last row. */
    public function fetchColumn(int $column = 0);

    /** Releases the result. Calling this more than once is safe. */
    public function closeCursor(): bool;
}
