# dbsync Design

**Date:** 2026-08-14
**Status:** Approved for implementation
**Scope:** `bin/dbsync.php` plus supporting `src/` classes, `composer.json`, `config.ini.sample`, `.gitignore`, `README.md`

## Problem

Copying a production database to a development or staging server with
`mysqldump` transfers every row of every table. Large tables dominate the
transfer and the resulting target consumes as much disk as production, which is
wasteful when the target only needs representative data.

Trimming the copy with `LIMIT` breaks the result. A limited child table keeps
rows whose parent rows were not kept, so foreign keys dangle and the target
database cannot be used for realistic testing.

`dbsync` copies a size-bounded subset of each large table while guaranteeing
that no copied row references a row that was not copied.

## Approach

Tables at or under a size threshold are copied whole. Tables over the threshold
are reduced by **subnetting**: rows are selected by a modulus on the integer
primary key, `pk % N = R`, where `N` is derived from the table's size so the
result lands near the threshold.

Referential integrity is then restored in two steps:

1. **Predicate pushdown** narrows the source `SELECT` where it is provably safe,
   reducing what crosses the network.
2. **A prune pass on the target** deletes any row whose foreign key does not
   resolve, using same-server joins.

The prune pass is what makes the design correct. Pushdown alone is not
sufficient, for the reason given below.

### Why pushdown alone fails

Consider `customers` → `orders` → `order_items`, all over the threshold.

```
customers    id % 3 = 0
orders       id % 12 = 0  AND  customer_id % 3 = 0
order_items  id % 20 = 0  AND  order_id % ?
```

The claim "every order with `id % 12 = 0` was copied" is false. Some were
dropped by the `customer_id % 3 = 0` term. `order_items` has no `customer_id`
column, so it cannot inherit the grandparent constraint, and filtering only on
`order_id % 12 = 0` admits items whose order was dropped.

Shipping the copied parent key sets between servers would fix this but does not
scale; a subnetted parent can still hold millions of keys.

Pruning on the target sidesteps both problems. Source and target rows never need
to meet, the join is local, and correctness holds at any depth.

### Predicate pushdown as an optimization

Pushdown is retained where it is safe. When a direct parent's filter is a pure
modulus on its own primary key with no inherited terms, every parent row
matching that modulus is guaranteed copied, so adding `child.fk % N_parent = R`
to the source `SELECT` removes only rows the prune pass would have deleted
anyway. It never changes the outcome, only the volume transferred.

Pushdown is skipped when the parent's filter carries any inherited term.

## Pipeline

### Phase 1 — Inspect and plan

For each database in scope, read from the source:

- Databases: `SHOW DATABASES`, which returns only what the connecting user can
  see. Excludes `mysql`, `information_schema`, `performance_schema`, `sys`, plus
  anything in `exclude_databases`.
- Tables: `SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'`. Views, triggers,
  and routines are out of scope.
- Sizes: `DATA_LENGTH + INDEX_LENGTH` and `TABLE_ROWS` from
  `information_schema.TABLES`.
- Columns: name, data type, numeric precision, column type string, nullability,
  from `information_schema.COLUMNS`.
- Primary keys and declared foreign keys: `information_schema.KEY_COLUMN_USAGE`
  and `TABLE_CONSTRAINTS`.

Each table is then classified:

| Class | Condition | Row filter |
|---|---|---|
| `FULL` | size ≤ threshold, **or** table has a self-referencing foreign key, **or** table is listed in `force_full_tables` | none |
| `SUBNET` | size > threshold and table has a single-column integer primary key | `pk % N = R` |
| `STRUCTURE_ONLY` | size > threshold and no single-column integer primary key | none copied; warn |

`N = max(1, ceil(size_bytes / threshold_bytes))`. `R` is `subnet_remainder`,
default 0.

Sizes from `information_schema` are InnoDB estimates, so `N` is approximate and
a table may land somewhat over or under the threshold. This is acceptable; the
threshold is a budget, not a contract.

Classification depends only on each table's own size, so it needs no ordering
pass.

**Self-referencing tables are exempt from subnetting.** Subnetting a hierarchy
and then pruning it would delete every node whose parent fell outside the
subnet, cascading up the tree and destroying it. Unlike cross-table orphans,
that is damage `dbsync` would be creating rather than inheriting, so such tables
are copied whole.

### Phase 2 — Relationship graph

Edges come from two sources.

**Declared** foreign keys are read from `information_schema`. Only
single-column foreign keys participate in subnetting and pruning; composite
foreign keys are recorded and reported but not used to filter, since the modulus
and prune logic are single-column.

**Inferred** edges apply where no declared constraint exists. Column `C` in
table `T` infers an edge to table `P` when:

- `P` is in the same database and `P ≠ T`,
- `P`'s primary key is a single column also named `C`,
- both columns are integers whose data type, size, and signedness match exactly
  — `INT UNSIGNED` matches `INT UNSIGNED`, and does not match `BIGINT UNSIGNED`
  or signed `INT`,
- `T.C` is not itself `T`'s primary key.

If two or more tables qualify as `P`, the edge is ambiguous: it is skipped and a
warning names both candidates. A declared constraint on a column always
overrides inference for that column.

**Known limitation.** This rule cannot infer a self-reference. A self-reference
requires the referencing column to have a different name from the primary key it
points at — a table cannot hold two columns of the same name — so the
name-equality rule never matches. Self-referencing tables are therefore exempted
only when the foreign key is *declared*. A schema with an undeclared
`categories.parent_id` will be subnetted and its hierarchy pruned. The
`force_full_tables` config key is the escape hatch; the dry-run report is where
the user would notice.

Declared foreign keys pointing at a schema outside the copy scope are reported
and treated as unconstrained, since the referenced table will not exist on the
target to prune against.

The graph supports a Kahn topological sort. Cycles are detected and reported;
they do not abort the run, because the prune pass iterates to a fixpoint rather
than relying on a strict ordering.

### Phase 3 — Copy

If `--drop` was given, `DROP DATABASE IF EXISTS` runs first. The target database
is created with `CREATE DATABASE IF NOT EXISTS`, using the source's default
character set and collation from `information_schema.SCHEMATA`.

Session setup on both connections, before any copying:

```sql
SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';
SET SESSION time_zone = '+00:00';
SET SESSION foreign_key_checks = 0;   -- target only
SET SESSION unique_checks = 0;        -- target only
```

`NO_AUTO_VALUE_ON_ZERO` preserves rows whose auto-increment key is literally
zero. Pinning both sessions to UTC stops `TIMESTAMP` values from shifting
between servers in different zones. The checks are re-enabled after Phase 4.

Both connections use `SET NAMES binary`. Copying with a character set would make
the server transcode values out and back, which corrupts data whenever a column
holds bytes not valid in the session encoding. Treating values as opaque bytes
reproduces them exactly, which is the same approach `mysqldump
--default-character-set=binary` takes. The per-column character sets in the
recreated DDL still control how the target interprets them.

Per table:

1. `DROP TABLE IF EXISTS` on the target, then the source's `SHOW CREATE TABLE`
   output verbatim. Keeping the statement unmodified preserves engine, charset,
   collation, indexes, `AUTO_INCREMENT` counter, and row format. Tables are
   always replaced completely, whether or not `--drop` was given.
2. `STRUCTURE_ONLY` tables stop here.
3. Otherwise stream rows with an unbuffered `SELECT` (`PDO::MYSQL_ATTR_USE_
   BUFFERED_QUERY = false`) so a large table never has to fit in PHP memory,
   ordered by primary key where one exists.
4. Write with multi-row prepared `INSERT`s on the target, with
   `PDO::ATTR_EMULATE_PREPARES = false`.

Rows per statement is bounded by three limits, taking the smallest:

- the configured `batch_size`, default 1000;
- `floor(60000 / column_count)`, staying under the 65535 placeholder ceiling;
- a byte budget — the batch flushes early once accumulated value bytes exceed
  one third of the target's `max_allowed_packet`.

Two prepared statements are reused per table: one sized for a full batch, one
rebuilt for the final partial batch.

A table that cannot be read — permissions, a corrupt table, an engine error — is
caught, warned about by name with the driver message, and skipped. The run
continues and exits with the partial-success code.

### Phase 4 — Prune

For every single-column foreign key edge whose parent table was classified
`SUBNET` or `STRUCTURE_ONLY`, run on the target:

```sql
DELETE c FROM `child` c
LEFT JOIN `parent` p ON c.`fk` = p.`pk`
WHERE c.`fk` IS NOT NULL AND p.`pk` IS NULL;
```

Edges whose parent was copied `FULL` need no prune; every parent row is present.

`NULL` foreign keys are left alone — a nullable, unset reference is not an
orphan.

A child with several parents gets one `DELETE` per edge, which produces the
required AND semantics: the row survives only if every one of its non-`NULL`
references resolves.

Deletes run in topological order, parents before children, so a row removed from
a mid-level table propagates to its own children within the same sweep. Because
cycles make a single ordered sweep insufficient, the whole sweep repeats until a
sweep deletes zero rows, to a maximum of 10 sweeps, after which it warns that
the graph did not converge.

Rows that were already orphaned in the source get deleted too. This is
intentional. The target ends up cleaner than the source, never dirtier.

### Phase 5 — Report

Per table: class, `N`, rows read, rows inserted, rows pruned, bytes transferred,
elapsed seconds. Totals: source size, target size re-queried from the target's
`information_schema` after the run, the resulting ratio, wall-clock time broken
out by phase, and a collected list of every warning.

## Components

```
bin/dbsync.php             CLI entry: argument parsing, orchestration, exit codes
src/Config.php             config.ini parsing, connection lookup, [sync] defaults
src/Connection.php         PDO construction, port default, session setup
src/SchemaInspector.php    databases, tables, sizes, columns, PKs, declared FKs
src/RelationshipGraph.php  declared + inferred edges, topological sort, cycles
src/SyncPlanner.php        table classification, N, WHERE clauses, pushdown
src/TableCopier.php        DDL recreate, streaming batched insert
src/Pruner.php             orphan deletion sweeps to fixpoint
src/Report.php             timing, counts, sizes, warning collection, output
```

The three classes carrying the real logic — `SyncPlanner`,
`RelationshipGraph`, and `Config` — are pure. They take arrays describing a
schema and return decisions, touching no database, which is what makes them
testable without a server.

## Configuration

Every section of `config.ini` is a named connection except the reserved `[sync]`
section.

```ini
[sync]
threshold_mb = 10
subnet_remainder = 0
batch_size = 1000
exclude_databases = "mysql,information_schema,performance_schema,sys"
force_full_tables = ""

[prod]
host = db1.example.invalid
user = readonly_user
password = "REPLACE_ME"
port = 3306

[dev]
host = 127.0.0.1
user = dev_user
password = "REPLACE_ME"
```

`port` defaults to 3306 when omitted. `config.ini` is listed in `.gitignore`;
`config.ini.sample` is committed and uses placeholder credentials only.

`force_full_tables` accepts a comma-separated list of `database.table` entries
that bypass subnetting.

## CLI

```
dbsync.php --source=NAME --target=NAME [options]

  --source=NAME     named connection to read from   (required)
  --target=NAME     named connection to write to    (required)
  --database=NAME   limit to one database; default is every readable database
  --drop            DROP DATABASE before creating it
  --config=PATH     config file path; default ./config.ini
  --dry-run         print the plan and exit without writing
  --threshold=MB    override the size threshold
  --help            usage
```

`--dry-run` prints the full plan — every table's class, `N`, estimated rows and
bytes, and every inferred edge — without connecting for writes. It exists so a
run against a real server can be inspected before it happens.

The source connection is treated as strictly read-only. `dbsync` issues no
statement that writes to it.

If `--database` names a database the source user cannot see, the run stops
before connecting to the target and exits `1`.

**Source and target must be different servers.** Every database is copied to the
same name on the target, so pointing both connections at one host means dbsync
overwrites the data it is reading — and with `--drop`, destroys it before the
first row is read. Distinct connection names are not sufficient protection, so
the check compares resolved host and port and refuses with exit `1`.

Exit codes: `0` success, `1` configuration or argument error, `2` connection
failure, `3` completed with warnings such as skipped or unsubnettable tables,
`4` fatal error during copy.

## Testing

`SyncPlanner`, `RelationshipGraph`, and `Config` are unit-tested against fixture
arrays with no database. Coverage includes: threshold boundary at exactly the
limit; `N` derivation; unsigned and signed integer type matching in inference;
ambiguous inference producing a warning rather than an edge; declared
constraints overriding inference; self-reference forcing `FULL`; topological
ordering; and cycle detection.

`SchemaInspector`, `TableCopier`, and `Pruner` get integration tests against a
real MySQL server, gated on a `DBSYNC_TEST_DSN` environment variable and skipped
when it is absent. These cover the three-level orphan case from the pushdown
discussion above, which is the scenario the whole design exists to handle, plus
binary and `NULL` value fidelity and the placeholder-limit batch split.

The end-to-end test additionally needs `DBSYNC_TEST_TARGET_DSN` naming a
*second* server, and skips without it. It cannot share one server with the
source, for exactly the reason the same-server guard above exists.

## MySQL 5.5 compatibility

No common table expressions, window functions, `JSON` type, generated columns,
`utf8mb4_0900_*` collations, or `information_schema` tables added after 5.5.
Multi-table `DELETE ... JOIN`, `SHOW CREATE TABLE`, `SHOW FULL TABLES`, and the
`information_schema` tables used here all predate 5.5. `SELECT ... WHERE pk % N
= R` is ordinary arithmetic.

Modulus on a subnetted table cannot use an index, so the source scans the table.
This is accepted: the alternative — a key-range scheme — samples unevenly and
biases toward whichever end of the key space it selects.

## Out of scope

Views, triggers, stored procedures, functions, events, and users. Incremental or
resumable sync. Parallel table copying. Schema-only diffing against an existing
target.
