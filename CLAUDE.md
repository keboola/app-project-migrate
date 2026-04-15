# app-project-migrate – AI Development Context

## What this repository does

This is the **master orchestrator** for migrating Keboola projects. It moves an entire project from one stack to another (e.g. AWS US → GCP EU). It does not move data directly – it enqueues and coordinates jobs in other Keboola components via Queue API v2.

## Documentation

- **`docs/overview.md`** – brief overview + links to other docs
- **`docs/architecture.md`** – full system architecture, all pipeline phases, what is migrated, what is skipped
- **`docs/configuration.md`** – complete configuration parameter reference with examples

## Required environment variables

Before running tests, verify that these variables are present in `.env` (or exported in the shell). If missing, ask for them explicitly.

**Required to run tests:**

| Variable | Description |
|---|---|
| `KBC_URL` | URL of the destination Keboola project (e.g. `https://connection.keboola.com`) |
| `KBC_TOKEN` | Storage token of the destination project (must have admin permissions) |

**Required for functional tests:**

| Variable | Description |
|---|---|
| `SOURCE_PROJECT_URL` | URL of the source project |
| `SOURCE_STORAGE_API_ADMIN_TOKEN` | Admin token of the source project |

**Required for specific unit tests:**

| Variable | Description |
|---|---|
| `KBC_TOKEN_NOT_MASTER` | Token without master permissions – tests rejection of unauthorized access |

**Platform-injected (automatically set by Keboola Runner):**

| Variable | Description |
|---|---|
| `KBC_RUNID` | Job run ID, used to link sub-jobs in `QueueV2JobRunner` |

> Variables are passed into the Docker container via `docker compose` (file `docker-compose.yml` in the repo root). Check that the `.env` file exists in the repo root – if not, create it based on the list above.

## Development commands

```bash
composer build                 # lint → phpcs → phpstan → tests
composer tests                 # tests-phpunit + tests-datadir
composer tests-phpunit         # unit tests
composer tests-datadir         # functional tests
composer phpstan               # static analysis
composer phpcs                 # code style
composer phpcbf                # auto-fix code style

# via Docker (preferred) – service name in docker-compose.yml is `dev`
docker compose run --rm dev composer phpcs
docker compose run --rm dev composer phpstan
docker compose run --rm dev composer tests
```

## Key files

| File | Purpose |
|---|---|
| `src/Migrate.php` | Pipeline logic – most important file in the repository |
| `src/Config.php` | Configuration getters (~50 methods) |
| `src/ConfigDefinition.php` | Parameter validation and default values |
| `src/Component.php` | Entry point; initializes both JobRunners, validates tokens |
| `src/JobRunner/JobRunner.php` | Interface for running jobs |
| `src/JobRunner/JobRunnerFactory.php` | Factory for JobRunner (source vs. destination) |
| `src/JobRunner/QueueV2JobRunner.php` | Enqueues jobs and waits for completion |
| `src/Migrator/DataGatewayMigrator.php` | Recreates Data Gateway workspaces |
| `src/Checker/AfterMigration.php` | Post-migration row count check |
| `src/Utils.php` | `checkMigrationApps()`, `checkIfProjectEmpty()` |

## Pipeline phases (src/Migrate.php)

| Phase | What runs | Condition |
|---|---|---|
| 1. Backup | `keboola.project-backup` via `sourceJobRunner` | always |
| 2. Credentials | sync action `generate-read-credentials` | always |
| 3. Restore | `keboola.project-restore` via `destJobRunner` | `migrateBuckets`, `migrateTables`, etc. |
| 4. Secrets | Encryption API for each configuration | `migrateSecrets: true` |
| 5. Table data | `keboola.app-project-migrate-large-tables` | `migrateBuckets && migrateTables && directDataMigration && !migrateStructureOnly` |
| 6. Snowflake Writers | `keboola.app-snowflake-writer-migrate` | `!migrateSecrets` |
| 7. Data Gateway | `DataGatewayMigrator` direct API calls | `migrateDataGateway: true` |
| 8. Post-check | `AfterMigration` row count comparison | always |

## What is skipped and why

### Components skipped during restore (Phase 3)

These components are skipped by `app-project-restore` – they are handled elsewhere:

```
'orchestrator'                      // Orchestrator Migrate App (outside this system)
'gooddata-writer'                   // GoodData Writer Migrate App (outside this system)
'keboola.wr-db-snowflake'          // Phase 6 (app-snowflake-writer-migrate)
'keboola.wr-snowflake-blob-storage' // Phase 6
'keboola.wr-db-snowflake-gcs'      // Phase 6
'keboola.wr-db-snowflake-gcs-s3'   // Phase 6
```

Orchestrator configurations are restored but automatically set to `isDisabled: true`.

## dataMode: database (Snowflake replication)

Mode `database` bypasses Storage API and uses native Snowflake replication. Significantly faster for large Snowflake-to-Snowflake migrations.

Predefined stack-to-Snowflake-account mappings are in `app-project-migrate-tables-data/src/Config.php`.

For other stacks or BYODB: `isSourceByodb: true` + `sourceByodb: <db_name>`.

## GCS large tables

Tables on GCP stacks that are sliced and >50 GB are migrated using parallel worker processes (`worker-chunk.php`) in `app-project-migrate-tables-data`. Number of workers: `gcsLargeTable.parallelChunks` (default 3, max 20).

## JobRunner and componentsDevTag

`QueueV2JobRunner` enqueues jobs and polls for completion. `componentsDevTag.*` parameters allow overriding the Docker image tag of sub-components – useful for testing a dev branch without releasing.

## Data Gateway

`DataGatewayMigrator` recreates a workspace for each unique source schema (RSA 2048-bit keypair). **Data in Data Gateway workspaces is NOT migrated** – the user must manually reload source data.

## Coding standards

- PHP 8.x with strict types
- PHPStan level max
- Keboola coding standard (PSR-12)
- `UserException` for user-visible errors (displayed in Keboola UI)
- `ApplicationException` for internal/unexpected errors

## Related repositories

| Component ID | Repository |
|---|---|
| `keboola.project-backup` | `app-project-backup` → `php-kbc-project-backup` |
| `keboola.project-restore` | `app-project-restore` → `php-kbc-project-restore` |
| `keboola.app-project-migrate-large-tables` | `app-project-migrate-tables-data` |
| `keboola.app-snowflake-writer-migrate` | `app-snowflake-writer-migrate` |
