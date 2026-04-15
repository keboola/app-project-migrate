# Migration system architecture – overview

## What the system does

The system allows moving an entire Keboola project from one stack to another (e.g. AWS US → GCP EU). It migrates component configurations, storage structure (buckets, tables), table data, triggers, notifications, permanent files, and encrypted secrets.

## Repositories

The system consists of 7 PHP repositories in two layers:

### PHP libraries (low-level logic)

| Repository | Role |
|---|---|
| `php-kbc-project-backup` | Backup project to cloud storage (S3/ABS/GCS) |
| `php-kbc-project-restore` | Restore project from cloud storage |

### Keboola App components (runnable as Keboola jobs)

| Repository | Component ID | Role |
|---|---|---|
| `app-project-backup` | `keboola.project-backup` | Backup wrapper |
| `app-project-restore` | `keboola.project-restore` | Restore wrapper |
| `app-project-migrate` | *(master orchestrator)* | Controls the entire pipeline |
| `app-project-migrate-tables-data` | `keboola.app-project-migrate-large-tables` | Direct table data migration |
| `app-snowflake-writer-migrate` | `keboola.app-snowflake-writer-migrate` | Snowflake Writer configuration migration |

## Pipeline

`app-project-migrate` is the entry point. It orchestrates other components via Keboola Queue API v2.

```
app-project-migrate
 │
 ├─ PHASE 1 ──── Backup source project
 │               Runs: app-project-backup (keboola.project-backup)
 │               Where: source project (sourceJobRunner)
 │
 ├─ PHASE 2 ──── Obtain backup access credentials
 │               Sync action: generate-read-credentials on app-project-backup
 │
 ├─ PHASE 3 ──── Restore structure in destination project
 │               Runs: app-project-restore (keboola.project-restore)
 │               Where: destination project (destJobRunner)
 │
 ├─ PHASE 4 ──── Migrate encrypted secrets [optional, migrateSecrets: true]
 │               Calls Encryption API directly for each configuration
 │
 ├─ PHASE 5 ──── Direct table data migration
 │               Conditions: migrateBuckets: true AND migrateTables: true
 │                          AND directDataMigration: true AND migrateStructureOnly: false
 │               Runs: app-project-migrate-tables-data
 │               Where: destination project (destJobRunner)
 │
 ├─ PHASE 6 ──── Migrate Snowflake Writers [if migrateSecrets: false]
 │               Runs: app-snowflake-writer-migrate
 │               Where: destination project (destJobRunner)
 │
 ├─ PHASE 7 ──── Migrate Data Gateway [optional, migrateDataGateway: true]
 │               DataGatewayMigrator – direct API calls
 │
 └─ PHASE 8 ──── Post-migration check
                AfterMigration – row count comparison
```

## Phase details

### Phase 1 + 2 – Backup

`app-project-backup` wraps `php-kbc-project-backup` and saves the backup to cloud storage.

**What is backed up:**
- Project metadata (default branch)
- Bucket and table definitions including column metadata
- Table data (gzip CSV or sliced files) – **see note below**
- All component configurations (including versions if `includeVersions: true`)
- Permanent files
- Triggers and notifications

> **Optimization:** When `directDataMigration: true` (default) or `migrateStructureOnly: true`, Phase 1 runs with `exportStructureOnly: true` – **table data is not backed up**, only structure. Data flows directly through Phase 5. This makes Phase 1 fast even for large projects.

**What is skipped:**
- Sys bucket tables
- Alias tables
- External schema tables
- Data Catalog tables (have `sourceBucket`)

After the backup, the sync action `generate-read-credentials` is called, which returns temporary read-only credentials for Phase 3. This allows the destination project to access the backup without sharing long-lived credentials.

### Phase 3 – Restore

`app-project-restore` wraps `php-kbc-project-restore` and restores the project structure.

**Sequence:**
1. Project metadata
2. Buckets
3. Component configurations (except those with special restore handling)
4. Tables (in parallel, worker scripts)
5. Alias tables
6. Triggers, notifications, files

> **Note:** If `migrateSecrets: true`, step 3 (configurations) is intentionally **skipped** (`restoreConfigs: false`). Configurations with encrypted secrets arrive complete via Encryption API in Phase 4. Without this, they would first be restored without secrets and then overwritten – unnecessary double SAPI calls.

**Skipped components** – must be migrated differently:

| Component ID | Where migrated |
|---|---|
| `orchestrator` | Orchestrator Migrate App (outside this system) |
| `gooddata-writer` | GoodData Writer Migrate App (outside this system) |
| `keboola.wr-db-snowflake` | Phase 6 (app-snowflake-writer-migrate) |
| `keboola.wr-snowflake-blob-storage` | Phase 6 |
| `keboola.wr-db-snowflake-gcs` | Phase 6 |
| `keboola.wr-db-snowflake-gcs-s3` | Phase 6 |

`keboola.orchestrator` configurations are restored but automatically set to `isDisabled: true`. Users must manually re-enable orchestrations after verifying the migration.

Tables are created in parallel using `symfony/process` worker scripts (`worker-create-table.php`). The number of parallel processes is controlled by `tableParallelism` (default: 5 in migrate, 10 in standalone restore).

### Phase 4 – Secrets migration

Calls Keboola Encryption API (`MigrationsClient::migrateConfiguration()`) for each configuration from the source project. Requires `#sourceManageToken`.

Skips: `orchestrator`, `gooddata-writer`.

For Snowflake Writers, additionally calls `preserveProperSnowflakeWorkspace()` – ensures consistent workspace reuse across multiple configurations of the same writer.

If `migrateSecrets: false` (default), encrypted secrets are **not migrated** and must be manually set in the destination. In this case, Snowflake Writers are handled by Phase 6.

### Phase 5 – Table data migration

Managed by `app-project-migrate-tables-data`. Has two modes:

**Mode `sapi` (default):** Export from source → upload to SAPI file storage → import to destination.

For large GCS tables (sliced + >50 GB), a parallel worker approach is used:
- Manifest is split into chunks (`gcsLargeTable.chunkSize` slices, default 150)
- Up to `gcsLargeTable.parallelChunks` (default 3, max 20) worker processes run in parallel
- Each worker (`worker-chunk.php`) downloads its chunk from GCS and uploads to SAPI
- Import to destination table happens incrementally
- PK is removed before import and restored after completion

**Mode `database`:** Direct Snowflake replication without going through Storage API – significantly faster for large data (see section below).

### Phase 6 – Snowflake Writers

`app-snowflake-writer-migrate` creates a new configuration for each Snowflake Writer. For Keboola-provisioned writers, it creates a workspace in the destination project, encrypts the password via Encryption API, and copies configurations and rows.

### Phase 7 – Data Gateway

`DataGatewayMigrator` iterates through all Data Gateway configurations in the destination project (restored in Phase 3, but without credentials) and creates a new workspace with an RSA keypair for each unique source schema.

**Note:** Data in Data Gateway workspaces is **not migrated**. Users must manually reload source data.

### Phase 8 – Post-migration check

`AfterMigration` compares row counts of all tables between source and destination. If they do not match, throws a `UserException`.

## Controlling individual migration parts

All optional phases can be controlled by parameters in `app-project-migrate`:

| Parameter | Default | Controls |
|---|---|---|
| `migrateProjectMetadata` | `true` | Phase 3: project metadata |
| `migrateBuckets` | `true` | Phase 3: buckets |
| `migrateTables` | `true` | Phase 3: tables (structure) |
| `migrateStructureOnly` | `false` | Phase 3: skips table data |
| `migratePermanentFiles` | `true` | Phase 3: permanent files |
| `migrateTriggers` | `true` | Phase 3: triggers |
| `migrateNotifications` | `true` | Phase 3: notifications |
| `migrateSecrets` | `false` | Phase 4: encrypted secrets |
| `directDataMigration` | `true` | Phase 5: direct data migration |
| `migrateDataGateway` | `true` | Phase 7: Data Gateway |
| `checkEmptyProject` | `true` | Refuses to start migration into a non-empty project |
| `dryRun` | `false` | Simulates the entire pipeline without actual changes |

## Parallel migration to GCP stacks

GCP stacks use GCS as the storage backend. Large tables (>50 GB, sliced) are migrated in a special way in `app-project-migrate-tables-data`:

1. File manifest is downloaded from GCS
2. Entries (slices) are split into chunks of `gcsLargeTable.chunkSize` (default 150)
3. Parallel worker processes (`worker-chunk.php`) are started – max `gcsLargeTable.parallelChunks` (default 3, max 20)
4. Each worker downloads its chunk from GCS, uploads to SAPI file storage and returns `fileId`
5. Import happens incrementally, PK is temporarily removed and restored after import

For Snowflake-to-Snowflake migrations to GCP stacks, **database mode** with direct Snowflake replication is also available (see `app-project-migrate-tables-data` documentation).

## Cloud storage backends

| Backend | Authentication |
|---|---|
| AWS S3 | `access_key_id` + `#secret_access_key` |
| Azure Blob Storage | `accountName` + `#accountKey` |
| GCS | `#jsonKey` (service account JSON) |

## Dependency graph

```
app-project-migrate
  ├─ keboola/storage-api-client
  ├─ keboola/job-queue-api-php-client
  ├─ keboola/sync-actions-client
  ├─ keboola/encryption-api-php-client
  └─ keboola/php-component

app-project-backup → php-kbc-project-backup
  └─ cloud SDKs (aws/google/azure)

app-project-restore → php-kbc-project-restore
  ├─ cloud SDKs
  └─ symfony/process (worker-create-table.php)

app-project-migrate-tables-data
  ├─ keboola/storage-api-client
  ├─ keboola/db-adapter-snowflake
  └─ symfony/process (worker-chunk.php)

app-snowflake-writer-migrate
  └─ keboola/storage-api-client
```
