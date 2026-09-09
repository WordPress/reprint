# Reading BIT columns as unsigned numbers

A `BIT(8)` column containing `49` must export as the number `49`. Some
drivers return that value as the packed byte `0x31`, which also spells the
text character `1`. Treating the fetched value as a number can therefore
export `1` instead of `49`.

[PR #785](https://github.com/WordPress/reprint/pull/785) addresses the packed
byte case by removing `BIT` from the numeric type list. That changes more
than the read: it also changes SQL values, primary-key comparisons, resume
cursors, and which values reach URL rewriting.

## Read a number; keep the existing numeric paths

`DatabaseRowsReader::build_byte_preserving_select_from_current_table()`
asks the database to return each BIT column as an unsigned integer:

```sql
SELECT CAST(`flags` AS UNSIGNED) AS `flags` FROM `bits`
```

The cast happens before the driver receives the result. MySQL and MariaDB
report an integer result type rather than a BIT result type. Drivers can
return that number as a PHP integer or a decimal string; the exporter
already accepts both. No PHP integer or float cast is needed, so the
decimal digits of `BIT(64)` values through `18446744073709551615` survive.
SQL NULL remains NULL.

`BIT` stays in `is_numeric_type()`. The SELECT changes, but the key predicates
and emitted SQL remain numeric. A BIT primary key can still use the existing
numeric range comparison without wrapping the indexed column in a binary
cast. The same read method serves PDO, the wpdb adapter, and the SQLite
adapter.

## Why not export packed bytes?

The regression tests exercise these consequences of #785's binary approach:

| Case | Result with binary handling | Reason to keep numeric handling |
| --- | --- | --- |
| SQLite source, BIT(8) values `0, 1, 49, 255` | MySQL receives `48, 49, 255, 255` under the dump's non-strict import mode. | SQLite's binary cast returns the bytes of decimal text, not MySQL's packed BIT bytes. |
| MySQL source, the same values | SQLite receives `0, 0, 1, 0`. | Packed bytes do not carry the numeric meaning expected by SQLite. |
| Resume after key `0` using an existing numeric cursor | A ten-row BIT(16) table finishes with only `0, 32768, 65535`. | Reinterpreting the saved number as bytes changes the next-key comparison and skips rows. |
| BIT(64) pattern `0x687474703a2f2f61` | Rewriting `http://a` to `http://b` changes the flag bits. | The packed bytes happen to spell a URL. Numeric SQL never sends this value through string rewriting. |
| Second batch of a BIT primary-key export | EXPLAIN shows a full index scan and filesort rather than a primary-key range read. | Cast the SELECT result without wrapping the indexed key in comparisons or ordering. |
| Live URL rewriting with a BIT(16) key of `0` | Strict-mode error 1292 aborts the command before it can rewrite a later INT-keyed table. | The updater's bound numeric comparison must not receive packed zero bytes. |

These tests use actual exports and imports. The resume fixture was captured
from trunk commit `248dd565` after emitting a complete one-row batch. A
separate result-metadata assertion fails on that unchanged reader: mysqlnd
already decodes native BIT values as numbers, so value assertions alone
would miss the driver-dependent problem.

`BitColumnsTest` covers both the ordinary SQL and prepared INSERT paths into
SQLite. It runs EXPLAIN on the reader's actual second-batch query, rather
than a handwritten substitute, and runs the real live URL rewrite processor
through a BIT-keyed table followed by an INT-keyed table. All eleven cases reject
#785's reader at `e388ce92` on MySQL 8.0 and MariaDB 10.11, then pass with the
unsigned read.

## Scope and limits

The tests cover widths 1, 7, 8, 9, 32, 63, and 64; zero, NULL, high bits, and
the maximum unsigned value; native and stringified PDO results; composite
BIT keys; and resuming between oversized text chunks. BOOLEAN remains
TINYINT handling, including stored values such as `2` and `-1`.

This change does not repair an already-written dump or convert a cursor
which previously captured raw BIT bytes. Start a fresh export after such a
failed run. The full unsigned range is tested between MySQL-family
databases; this does not add unsigned 64-bit storage to SQLite. Handling of
DECIMAL, floating-point values, dates, JSON, spatial columns, ENUM, SET, and
generated columns is unchanged.

The live URL updater also has a pre-existing BIT-key matching problem which
can leave URLs unchanged. The live regression test protects against the new
abort and verifies that a later INT-keyed row is rewritten. It neither
claims to solve the older matching problem nor requires that bug to remain.
