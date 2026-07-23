# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
