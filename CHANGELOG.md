# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 2.0.3 — 2026-08-04

### Fixed

- Require `yiisoft/db-migration` ^2.1, which fixes `setSourceNamespaces()` matching a sibling namespace as a parent (upstream [yiisoft/db-migration#350](https://github.com/yiisoft/db-migration/pull/350)). Drop the manual `Injector::make()` migration workaround from both READMEs.

## 2.0.2 — 2026-08-02

- **Fix: the migration created an unusable `id` column.** It declared
  `'id' => 'bigprimarykey'`, which is not a type token — the real one is `bigpk`
  (`ColumnBuilder::bigPrimaryKey()`). MySQL and PostgreSQL reject that DDL, so
  the table was never created there; SQLite stores an unknown type verbatim, so
  the table existed but `id` had no autoincrement and every row read back
  `id = NULL`.

  **An installation that applied the migration on SQLite must recreate the
  table** (`down()` then `up()`); rows written so far carry no usable
  identifier. Installations on MySQL or PostgreSQL never got past the
  migration and need no cleanup.

- Tests: the migration suite now asserts that `id` is an autoincrementing
  primary key and reads inserted identifiers back, and that no unknown type
  token reaches the DDL. The previous suite only checked that each column
  existed, which an unknown type satisfies.

## 2.0.1 — 2026-08-01

- Docs: the documented `setSourceNamespaces()` migration registration does not
  find the bundled migration and never has — `yiisoft/db-migration` matches the
  PSR-4 map by string prefix and resolves into the core package, so
  `./yii migrate:up` exits 0 having created nothing. Both READMEs now say so and
  give a working `Injector`-based recipe until the upstream fix ships.

## 2.0.0 — 2026-07-25

**Breaking.** See [UPGRADE.md](UPGRADE.md) — an installation that already
applied the migration must rewrite one row in the `migration` table.

- The bundled migration moved to `Rasuvaeff\Yii3WorkflowDb\Migration\M260722000000CreateWorkflowTransitionsTable`
  (`src/Migration/`, PSR-4 autoloaded) from a global class in `migrations/`.
  Register it with `setSourceNamespaces()` instead of a `vendor/` path. Being
  autoloadable is what makes it safe to reference in DI at all: with the old
  global class, adding any container definition for it made
  `Yiisoft\Di\Container` fatal at build time in every request, because
  `new ReflectionClass()` ran before the migration runner had required the file.
- **The documented way to rename the table never worked.**
  `M...::class => ['__construct()' => ['table' => ...]]` is ignored:
  `yiisoft/db-migration` builds migrations through `Injector::make()`, which
  resolves arguments by name or type from the container and does not read
  definitions keyed by the migration's class — and a scalar `string $table` has
  no type to resolve. Users following the README silently got the default name.
- The table name is now a typed value object that `Injector` *can* resolve,
  built by `config/di.php` from params. One source of truth: the migration and
  `DbTransitionLog` cannot disagree any more (in 1.x the runtime read params while the
  migration used its own default, so configuring params pointed the runtime at a
  table the migration had never created).
- New `table_prefix` param, prepended to `table` — a single place to keep
  package tables out of the way of an application's own.
- All three index names (including the driver-specific unique idempotency
  index on MSSQL/Oracle/others) are derived from the table name. Unchanged for
  the default table name; in PostgreSQL, where index names are unique per
  schema rather than per table, hard-coded names collided between two
  installations sharing a schema.
- Identifier validation moved from two duplicated `preg_match` calls (in the
  migration and in `DbTransitionLog`) into the shared value object.


## 1.0.0 — 2026-07-24

- Initial package: `DbTransitionLog` implements the core's `TransitionLog` on
  `yiisoft/db`, storing one row per committed transition with ATOM timestamps.
- A unique index on `(workflow, subject_id, idempotency_key)` makes replay
  protection an invariant rather than a check; an integrity violation on a keyed
  record is translated into the core's `DuplicateIdempotencyKey`.
- Migration for the `workflow_transitions` table, plus `latest()` paging for
  admin screens and `prune()` with the `workflow:transitions:prune` command.
- Binds `TransitionLog` in DI as the single source for that key; the table name
  and the default retention come from `params.php`.
- Timestamps are normalised to UTC before storage, so the lexicographic
  ordering `prune()` relies on holds under DST switches and mixed-timezone
  servers.
- The migration adapts the unique index to the driver: filtered on MSSQL and
  function-based on Oracle, where NULLs in a unique index compare as equal and
  a plain index would reject the second key-less row of a subject.
- `workflow:transitions:prune` accepts only whole positive day counts and
  documents that pruning erases idempotency keys along with history.
- `WorkflowTransaction::applyOnce()` runs the transition and the caller's
  persistence (`then` closure) in one database transaction, so a failed save
  cannot burn the idempotency key.
- `DbTransitionLog::count()` returns the per-workflow total an admin pager
  needs next to `latest()`.
- `append()` runs its INSERT under a savepoint: on PostgreSQL a rejected
  duplicate would otherwise abort the caller's transaction before the
  violation can be classified.
