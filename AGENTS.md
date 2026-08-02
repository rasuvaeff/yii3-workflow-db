# AGENTS.md — yii3-workflow-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/yii3-workflow-db` (namespace `Rasuvaeff\Yii3WorkflowDb`) is the
database backend for `rasuvaeff/yii3-workflow`: it implements the core's
`Audit\TransitionLog` on `yiisoft/db`, ships the migration for the table, and
adds a retention command. No workflow logic lives here.

Public API: `DbTransitionLog`, `WorkflowTransaction`,
`Command\WorkflowTransitionsPruneCommand`, the migration.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **The unique index is the product.** Anything that weakens
   `(workflow, subject_id, idempotency_key)` — dropping it, making it partial,
   swallowing `IntegrityException` — removes the only reason this package exists
   over a hand-written 40-line implementation.
4. **Preserve the public contract.** Update README.md + README.ru.md + llms.txt
   + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer test:integration
```

Or with Make from inside the package: `make build`, `make cs-fix`, `make psalm`,
`make test`, `make test-integration`, `make mutation`.

## Invariants & gotchas

- **The table name is a VO, not a string, because `Injector` cannot resolve a
  scalar.** `yiisoft/db-migration` builds migrations via `Injector::make()`,
  which resolves arguments by name or by type and never reads a container
  definition keyed by the migration's own class. Never reintroduce a scalar
  `string $table` on a migration.
- **One source of truth for the name and its validation.** `config/di.php`
  builds `WorkflowTransitionsTableName` from `table_prefix` + `table` params and
  passes it to both the log and the migration; the identifier regex lives only
  in the VO (it used to be duplicated in two files).
- **All three index names derive from the table name**, including the
  driver-specific unique idempotency index. In PostgreSQL index names are unique
  per schema, not per table.
- Migrations live in `src/Migration/` and are therefore covered by cs, psalm and
  infection. `MigrationTableNameTest` asserts the column set and each index's
  columns.
- `setSourceNamespaces()` does NOT find them on any released
  `yiisoft/db-migration` (≤ 2.0.1): it matches the PSR-4 map by string prefix,
  so `Rasuvaeff\Yii3WorkflowDb\Migration` resolves into the core package and
  discovery silently finds zero — `migrate:up` exits 0 having created nothing.
  Until an upstream release carries the fix, migrations are applied directly via
  `Injector::make($class)->up($builder)` — see the README.
- `composer test` runs only the Unit suite; `composer mutation` runs every
  suite. An integration test left pointing at `migrations/` passes the first and
  fails the second.
- `append()` translates `IntegrityException` into the core's
  `DuplicateIdempotencyKey`, but only when the record actually carries a key;
  any other integrity failure (a schema drift, a NOT NULL violation) must
  propagate unchanged instead of being reported as a replay.
- The audit row is written inside `apply()`, so a rejected insert leaves the
  subject mutated in memory. That is documented in both READMEs with a
  transaction recipe — keep that section accurate.
- Timestamps are ATOM strings normalised to UTC, not native datetimes: ordering
  must stay lexicographic across drivers, and `prune()` compares strings. The
  UTC normalisation in `DbTransitionLog::utc()` is load-bearing — with a varying
  offset (DST, mixed-timezone servers) the string comparison breaks.
- The migration branches the unique index by driver: plain on MySQL/PostgreSQL/
  SQLite (NULLs are distinct), filtered (`WHERE idempotency_key IS NOT NULL`) on
  MSSQL, function-based (CASE expressions) on Oracle — both compare NULLs as
  equal, and most audit rows carry no key. Only the SQLite branch is covered by
  tests; treat the other two as best-effort and keep them in sync.
- `prune()` deletes idempotency keys along with history: the replay-protection
  window equals the retention window. That trade-off is documented in both
  READMEs — keep it that way.
- `WorkflowTransaction::applyOnce()` exists so a failed save cannot burn the
  key: the `then` closure runs inside the SAME transaction and only when the
  transition applied. It is deliberately not bound in `config/di.php` — the
  container autowires it from `ConnectionInterface`.
- `Query::all()` is typed loosely; rows go through `hydrateAll()`, which drops
  non-array rows and validates every column with `string()`. Do not "simplify"
  that into a bare `array_map`, psalm level 1 rejects it and a half-hydrated
  record is worse than an exception.
- The SQL schema is declared twice: in `src/Migration/` and inline in the tests
  (and in `examples/audit-trail.php`). Change one, change all three.
- Unit and integration tests run on in-memory SQLite through `yiisoft/db-sqlite`,
  so the integration suite covers real SQL and the migration, with no server.
  `composer build` runs the unit suite; use `composer test:integration` for the
  integration suite. SQLite's type
  affinity coerces values, so type-guard tests need a genuinely wrong shape
  (a NULL column), not a wrong scalar.
- `config/di.php` binds `TransitionLog` — this package is the single source for
  that key. The core must never bind it (`yiisoft/config` forbids duplicates).
  `tests/ConfigWiringTest.php` exercises the definitions inside the build gate.
- Test doubles come from `yiisoft/test-support` (`StaticClock`,
  `MemorySimpleCache`); do not hand-roll PSR doubles.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment. Never revert
  to floating `@vN` tags; updates go through Dependabot. Workflows carry
  `permissions: { contents: read }` and `persist-credentials: false` on every
  checkout. Verify with `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
