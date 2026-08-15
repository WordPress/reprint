# Design choices

This document records Reprint behavior that future changes must preserve.

## Database migrations preserve source storage engines

A source database may contain this table:

```sql
CREATE TABLE `wp_posts` (...) ENGINE=MyISAM;
```

For a MySQL-compatible target, Reprint preserves `ENGINE=MyISAM` by writing the
`SHOW CREATE TABLE` result into the SQL dump. The same rule applies to any
storage-engine clause. This happens in
[`MySQLDumpProducer::emit_create_table_statement()`](../packages/reprint-server/src/class-mysql-dump-producer.php#L349-L379).

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
[`MySQLDumpProducer::get_session_setup_sql()`](../packages/reprint-server/src/class-mysql-dump-producer.php#L430-L438).

SQLite has no MyISAM or InnoDB backend, so a SQLite target must translate the
source definition.
