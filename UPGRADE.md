# Upgrade guide

## 1.x → 2.0

The bundled migration moved into the package namespace:

```
M260722000000CreateWorkflowTransitionsTable
→ Rasuvaeff\Yii3WorkflowDb\Migration\M260722000000CreateWorkflowTransitionsTable
```

`yiisoft/db-migration` stores the applied migration's class name verbatim in the
`migration` table. Without the two steps below, `migrate:up` sees the namespaced
class as a *new* migration and fails with "table already exists".

### 1. Rewrite the applied migration's name

```sql
UPDATE migration
SET name = 'Rasuvaeff\\Yii3WorkflowDb\\Migration\\M260722000000CreateWorkflowTransitionsTable'
WHERE name = 'M260722000000CreateWorkflowTransitionsTable';
```

Run this **before** the first `migrate:up` on 2.0. If you have never applied the
migration, skip it — there is nothing to rename.

### 2. Register by namespace instead of by path

```diff
 MigrationService::class => [
-    'setSourcePaths()' => [[__DIR__ . '/../vendor/rasuvaeff/yii3-workflow-db/migrations']],
+    'setSourceNamespaces()' => [['Rasuvaeff\\Yii3WorkflowDb\\Migration']],
 ],
```

The path form no longer resolves: `migrations/` is gone and the class lives
under `src/Migration/`, autoloaded via PSR-4.

### 3. Remove any DI definition of the migration

```diff
-M260722000000CreateWorkflowTransitionsTable::class => [
-    '__construct()' => ['table' => 'my_table'],
-],
```

That recipe was documented in 1.x and **never worked** — the migration is built
by `Injector::make()`, which resolves arguments by type and ignores container
definitions keyed by the migration's class. It also makes the container fatal at
build time in every request, because the class is not autoloadable until the
migration runner requires it.

Set the table name in params instead; the same value now reaches the migration
and `DbTransitionLog`:

```php
'rasuvaeff/yii3-workflow-db' => [
    'table' => 'my_table',
    'table_prefix' => '',
],
```

### Defaults are unchanged

The default table and index names are exactly what 1.x produced, so this release
needs no schema migration — only the `migration` table row above.
