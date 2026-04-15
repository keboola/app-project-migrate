# app-project-migrate – overview

## What the component does

This is the **master orchestrator** for migrating Keboola projects between stacks (e.g. AWS US → GCP EU). It does not move data directly – it enqueues and coordinates jobs in other Keboola components via Queue API v2.

Component ID: `keboola.app-project-migrate` (does not run itself via `app-project-migrate`).

## Further reading

This component is extensive and its documentation is split into several files:

| Document | Contents |
|---|---|
| [`architecture.md`](architecture.md) | Full system architecture, all pipeline phases, what is migrated, what is skipped, dependency graph |
| [`configuration.md`](configuration.md) | Complete configuration parameter reference with examples |
| [`../CLAUDE.md`](../CLAUDE.md) | AI development context, env variables, commands |

## Pipeline quick reference

```
PHASE 1 – Backup source project          (app-project-backup)
PHASE 2 – Obtain read credentials        (sync action)
PHASE 3 – Restore structure in target    (app-project-restore)
PHASE 4 – Migrate secrets                [optional, Encryption API]
PHASE 5 – Direct table data migration    [optional, app-project-migrate-tables-data]
PHASE 6 – Migrate Snowflake Writers      (app-snowflake-writer-migrate)
PHASE 7 – Migrate Data Gateway           [optional]
PHASE 8 – Post-migration check           (row count comparison)
```

See `architecture.md` for details on each phase.

## Key files

| File | Description |
|---|---|
| `src/Component.php` | Entry point |
| `src/Migrate.php` | Orchestrates all 8 pipeline phases |
| `src/Config.php` | Configuration getters |
| `src/ConfigDefinition.php` | Parameter validation |
| `src/JobRunner/` | Job execution via Queue API v2 |
| `src/DataGatewayMigrator.php` | Phase 7 |
| `src/AfterMigration.php` | Phase 8 |

## Related repositories

- Runs: `app-project-backup`, `app-project-restore`, `app-project-migrate-tables-data`, `app-snowflake-writer-migrate`
- Libraries (indirect): `php-kbc-project-backup`, `php-kbc-project-restore`
