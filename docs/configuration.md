# Configuration parameter reference – app-project-migrate

## Required parameters

| Parameter | Description |
|---|---|
| `#sourceKbcToken` | Storage token of the source Keboola project |

`#sourceManageToken` is additionally required if `migrateSecrets: true`.

## Migration switches

Default value is `true` unless stated otherwise.

| Parameter | Default | Description |
|---|---|---|
| `migrateProjectMetadata` | `true` | Restores default branch metadata |
| `migrateBuckets` | `true` | Restores storage buckets |
| `migrateTables` | `true` | Creates table structures |
| `migrateStructureOnly` | `false` | If `true`, skips table data migration (structure only) |
| `migratePermanentFiles` | `true` | Restores permanent files |
| `migrateTriggers` | `true` | Restores flow triggers |
| `migrateNotifications` | `true` | Restores notification subscriptions |
| `migrateSecrets` | `false` | Re-encrypts secrets via Encryption API (requires `#sourceManageToken`) |
| `directDataMigration` | `true` | Runs direct table data migration (Phase 5) |
| `migrateDataGateway` | `true` | Recreates Data Gateway workspaces |

## Safety switches

| Parameter | Default | Description |
|---|---|---|
| `checkEmptyProject` | `true` | Rejects migration into a non-empty destination project |
| `dryRun` | `false` | Simulates the entire pipeline without making actual changes |
| `skipRegionValidation` | `false` | Skips validation that project region matches storage backend region |

## Source project

| Parameter | Default | Description |
|---|---|---|
| `sourceKbcUrl` | `https://connection.keboola.com` | URL of the source stack |
| `#sourceKbcToken` | REQUIRED | Storage token |
| `#sourceManageToken` | – | Manage token (required only if `migrateSecrets: true`) |

## Data migration options

| Parameter | Default | Description |
|---|---|---|
| `dataMode` | `sapi` | `sapi` = via Storage API; `database` = via Snowflake replication |
| `preserveTimestamp` | `false` | Preserves `_timestamp` column values from source data |
| `forcePrimaryKeyNotNull` | `false` | Forces NOT NULL on primary key columns in typed tables |
| `tableParallelism` | `5` | Number of parallel workers for table creation |

## GCS large table tuning

Applies only to sliced tables larger than 50 GB on GCP stacks.

| Parameter | Default | Limits | Description |
|---|---|---|---|
| `gcsLargeTable.parallelChunks` | `3` | 1–20 | Number of parallel worker processes |
| `gcsLargeTable.chunkSize` | `150` | min 1 | Number of GCS slices per chunk |

## Database mode (`dataMode: database`)

Required if `dataMode: database`. Forbidden if `dataMode: sapi`.

| Parameter | Description |
|---|---|
| `db.host` | Snowflake host |
| `db.username` | Snowflake username |
| `db.#password` | Password (one of `#password` or `#privateKey` is required) |
| `db.#privateKey` | Private key for key-pair authentication |
| `db.warehouse` | Snowflake warehouse name |
| `db.warehouse_size` | `SMALL` (default) / `MEDIUM` / `LARGE` |

| Parameter | Default | Description |
|---|---|---|
| `isSourceByodb` | `false` | Source uses BYODB (non-standard Snowflake account) |
| `sourceByodb` | – | BYODB database name |
| `includeWorkspaceSchemas` | `[]` | Workspace schemas to include in database mode |

## Dev image tags

Allow testing specific Docker image tags of sub-components without releasing.

| Parameter | Description |
|---|---|
| `componentsDevTag.backup` | Override Docker tag for `keboola.project-backup` |
| `componentsDevTag.restore` | Override Docker tag for `keboola.project-restore` |
| `componentsDevTag.tablesData` | Override Docker tag for `keboola.app-project-migrate-large-tables` |

## Configuration examples

### Minimal migration (structure only, no data)

```json
{
  "parameters": {
    "sourceKbcUrl": "https://connection.keboola.com",
    "#sourceKbcToken": "xxx",
    "migrateStructureOnly": true,
    "directDataMigration": false
  }
}
```

### Full migration with secrets

```json
{
  "parameters": {
    "sourceKbcUrl": "https://connection.keboola.com",
    "#sourceKbcToken": "xxx",
    "#sourceManageToken": "yyy",
    "migrateSecrets": true,
    "directDataMigration": true,
    "tableParallelism": 10,
    "gcsLargeTable": {
      "parallelChunks": 5,
      "chunkSize": 200
    }
  }
}
```

### Database mode (direct Snowflake replication)

```json
{
  "parameters": {
    "sourceKbcUrl": "https://connection.keboola.com",
    "#sourceKbcToken": "xxx",
    "dataMode": "database",
    "db": {
      "host": "keboola.snowflakecomputing.com",
      "username": "svc_migrate",
      "#password": "secret",
      "warehouse": "MIGRATE_WH",
      "warehouse_size": "MEDIUM"
    }
  }
}
```

### Configurations only (no storage data)

```json
{
  "parameters": {
    "sourceKbcUrl": "https://connection.keboola.com",
    "#sourceKbcToken": "xxx",
    "migrateBuckets": false,
    "migrateTables": false,
    "directDataMigration": false,
    "migratePermanentFiles": false
  }
}
```
