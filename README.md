# dbtools

MySQL database tools written in PHP.

## Requirements

- PHP 8.3 or later, with `pdo` and `pdo_mysql`
- MySQL 5.5 or later on both the source and target servers

The SQL is deliberately plain. Nothing here depends on features added after
MySQL 5.5, so the same tool works against old and current servers.

## Installation

```bash
composer install
cp config.ini.sample config.ini
```

Then edit `config.ini` with real credentials. It is gitignored.

## Configuration

Every section of `config.ini` is a named database connection except the
reserved `[sync]` section. `port` defaults to `3306` when omitted.

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

## dbsync

Copies databases from one server to another without copying every row of
every table.

```bash
bin/dbsync.php --source=prod --target=dev
bin/dbsync.php --source=prod --target=dev --database=shop --drop
bin/dbsync.php --source=prod --target=dev --dry-run
```

### Options

| Option | Meaning |
| --- | --- |
| `--source=NAME` | Named connection to read from. Required. |
| `--target=NAME` | Named connection to write to. Required. |
| `--database=NAME` | Limit to one database. Default is every readable database. |
| `--drop` | `DROP DATABASE` before creating it. Without this, existing tables are still replaced. |
| `--config=PATH` | Config file path. Default `./config.ini`. |
| `--dry-run` | Print the plan and exit without writing anything. |
| `--threshold=MB` | Override the size threshold. |
| `--verbose` | Trace every table, batch, and byte budget to STDERR. |
| `--help` | Usage. |

Exit codes: `0` success, `1` configuration or argument error, `2` connection
failure, `3` completed with warnings, `4` fatal error during copy.

### max_allowed_packet on the target

A row travels to the target inside a single MySQL packet. When a statement
exceeds the target's `max_allowed_packet`, the server does not reject it — it
closes the connection, and every statement afterwards fails with the misleading
`SQLSTATE[HY000]: General error: 2006 MySQL server has gone away`. Targets
running older defaults (1 MB) hit this on any table holding a `BLOB` or `TEXT`
value bigger than that, even when the source allows 256 MB.

dbsync sizes each `INSERT` to stay under the target's limit, and skips a row it
cannot send at all rather than killing the connection. Each skipped row is
reported by table, primary key, size, and the `max_allowed_packet` value needed
to copy it:

```
Skipped one row of MARC.Outlines (lesID=71): estimated 2890589 bytes exceeds the
largest row the target "sbx_mirror" can accept (983040 bytes, from
max_allowed_packet=1048576).
```

To copy those rows, raise `max_allowed_packet` on the *target* server to above
the largest row and rerun. The run also warns up front whenever the target's
limit is below the source's. If the link does drop anyway, dbsync aborts
immediately with the table, batch size, byte estimate, SQLSTATE, and MySQL error
number, instead of letting every remaining table report the same 2006.

### Source and target must be different servers

Each database is copied to the *same name* on the target. If both connections
point at one server, dbsync would overwrite the data it is reading — and with
`--drop`, destroy it before reading a single row. Two differently named
connections pointing at the same host and port are still the same server, so
dbsync compares host and port and refuses to run.

### Why not mysqldump

`mysqldump` copies every row, so the target ends up as large as production.
Trimming with `LIMIT` breaks the result instead: a limited child table keeps
rows whose parent rows were dropped, leaving foreign keys dangling.

`dbsync` copies small tables whole and reduces large ones by **subnetting** —
keeping rows where `pk % N = R`, with `N` derived from the table's size so the
result lands near the threshold. It then repairs integrity by deleting, on the
target, any row whose foreign key no longer resolves. Because that runs as a
same-server join after loading, it stays correct however deep the foreign key
chain goes.

Foreign keys are read from declared constraints. Where none exist, a
relationship is inferred when a column matches another table's single-column
integer primary key by exact name, integer type, size, and signedness — so
`orders.customer_id` resolves to `customers.customer_id`, but not to a
`BIGINT` or a signed key.

Tables with a self-referencing foreign key are copied whole. Subnetting a
hierarchy would delete every node whose parent fell outside the subnet,
cascading up and destroying the tree. That detection only works for *declared*
self-references; for an undeclared one, list the table in `force_full_tables`.

A table over the threshold with no single-column integer primary key cannot be
subnetted. Its structure is created and a warning names it, but no rows are
copied.

### Scope

`dbsync` copies base tables and their rows. Views, triggers, stored
procedures, functions, events, and users are not copied.

## License

MIT. See [LICENSE](LICENSE).
