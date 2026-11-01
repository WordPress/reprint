# Design decisions

This document records Reprint behavior that future changes must preserve.

## Database migrations preserve source storage engines

A source database may contain this table:

```sql
CREATE TABLE `wp_posts` (...) ENGINE=MyISAM;
```

For a MySQL-compatible target, Reprint preserves `ENGINE=MyISAM` by writing the
`SHOW CREATE TABLE` result into the SQL dump. The same rule applies to any
storage-engine clause. This happens in
[`MySQLDumpProducer::emit_create_table_statement()`](../../packages/reprint-server/src/class-mysql-dump-producer.php#L349-L379).

The storage engine is part of the schema. It can change full-text search
results, locking, transactions, grouped `AUTO_INCREMENT` sequences, and
compatibility with `MERGE` tables. Reprint preserves the source schema; it does
not upgrade it. An engine conversion must be a separate operation that lists
the affected tables and requires an explicit choice.

The target database has the final say. Reprint sets the import session SQL mode
without `NO_ENGINE_SUBSTITUTION`. A target that accepts MyISAM creates a MyISAM
table. A target configured to enforce InnoDB may create an InnoDB table instead
of rejecting the import. The target makes that substitution; Reprint does not
rewrite the `CREATE TABLE` statement. The session settings are defined by
[`MySQLDumpProducer::get_session_setup_sql()`](../../packages/reprint-server/src/class-mysql-dump-producer.php#L430-L438).

SQLite has no MyISAM or InnoDB backend, so a SQLite target must translate the
source definition.

## Read BIT columns as numbers

A `BIT(8)` value of `49` can arrive from a database driver as the packed byte
`0x31`, which is also the text character `1`. Treating that byte as a decimal
number changes the value.

`DatabaseRowsReader::build_byte_preserving_select_from_current_table()` asks
the database for `CAST(column AS UNSIGNED)` before the driver reads the result.
Keep BIT in the numeric SQL and key-comparison paths. Casting only the selected
value preserves numeric resume cursors and avoids casting the indexed key in
range comparisons. It also keeps arbitrary flag bits out of URL rewriting.
Do not cast `BIT(64)` through a PHP integer or float; unsigned decimal digits
must survive through `18446744073709551615`. This read conversion does not
extend SQLite's unsigned 64-bit storage or address the separate BIT-key matching
limitation in live URL rewriting.

The cross-database, driver, key, resume, and query-plan cases live in
[BitColumnsTest](../../tests/MySQLDumpProducer/BitColumnsTest.php). Historical
failure matrices and the rejected patch remain in the associated PRs and Git
history rather than a second maintained specification.
