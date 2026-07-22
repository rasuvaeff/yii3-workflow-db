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
