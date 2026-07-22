# Examples

Runnable scripts showing typical `rasuvaeff/yii3-workflow-db` usage. Each script
is self-contained: `php examples/<name>.php` prints a trace and exits cleanly.

| Script | Shows | External dependencies |
|---|---|---|
| `audit-trail.php` | A workflow wired to `DbTransitionLog` on in-memory SQLite: history rows, a replayed idempotency key skipped by the pre-flight lookup, the unique index rejecting a concurrent write, and `prune()` | None (SQLite in memory) |

## Running

```bash
php examples/audit-trail.php
```

The script creates the schema inline, mirroring
`migrations/M260722000000CreateWorkflowTransitionsTable`. In an application you
run that migration instead and let the container build `DbTransitionLog`.
