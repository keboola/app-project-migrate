# App Project Migrate

[![GitHub Actions](https://github.com/keboola/app-project-migrate/actions/workflows/push.yml/badge.svg)](https://github.com/keboola/app-project-migrate/actions/workflows/push.yml)

Application which orchestrates whole process of Keboola Connection project migration from one stack to another.

Prerequisites:
 - Source project which will be migrated
 - Destination project - empty project where the source project will be cloned
 
Application is executed in *destination* project and requires Storage API token and Keboola Connection URL of *source* project.
Admin token of source project is required for GoodData writers migration.
Source project is left without any changes.

Migration steps performed by the application:

- Create snapshot of source project https://github.com/keboola/app-project-backup
- Restore project from snapshot https://github.com/keboola/app-project-restore
- Migrate GoodData writers https://github.com/keboola/app-gooddata-writer-migrate
- Migrate Snowflake writers https://github.com/keboola/app-snowflake-writer-migrate
- Migrate Orchestrators https://github.com/keboola/app-orchestrator-migrate


## Usage

It is recommended to run [migration validation application](https://github.com/keboola/app-project-migrate-validation) in the source project before migration.

Run the migration in destination project wil the following command.
This is example of project migration from US to EU, please replace these parameters:

- `DEST_PROJECT_SAPI_TOKEN` - Storage API token associated to admin of destination EU project (master)
- `SOURCE_PROJECT_SAPI_TOKEN` - Storage API token associated to admin of source US project (non-master, all permissions are required)
- `SOURCE_MANAGE_API_TOKEN` - Manage API token with super admin rights. Must be from source stack.
  Required if parameter `migrateSecrets` is `true`.

### Queue v2

```shell
curl -X POST \
--location 'https://queue.eu-central-1.keboola.com/jobs' \
--header 'X-StorageApi-Token: DEST_PROJECT_SAPI_TOKEN' \
--header 'Content-Type: application/json' \
--data '{
  "component": "keboola.app-project-migrate",
  "mode": "run",
  "configData": {
    "parameters": {
      "sourceKbcUrl": "https://connection.keboola.com",
      "#sourceKbcToken": "SOURCE_PROJECT_SAPI_TOKEN",
      "directDataMigration": true,
      "dryRun": false,
      "dataMode": "sapi",
      "directDataMigration": true,
      "migrateSecrets": false,
      "migratePermanentFiles": false,
      "migrateTriggers": true,
      "migrateNotifications": true,
      "migrateStructureOnly": true,
      "migrateSecrets": true,
      "migrateBuckets": true,
      "migrateTables": true,
      "migrateProjectMetadata": true,
      "skipRegionValidation": true,
      "#sourceManageToken": "SOURCE_MANAGE_API_TOKEN"
    }
  }
}'
```

#### Parameters Description

The request contains the following parameters:

- `sourceKbcUrl`: URL of the source Keboola Connection project
- `#sourceKbcToken`: Token for accessing the source project
- `#sourceManageToken`: Manage API token with super admin rights from the source stack
- `dataMode`: "sapi" - Data transfer mode via Storage API
- `directDataMigration`: Enables direct data migration between projects
- `dryRun`: When set to true, performs a test run without actual migration
- `migrateSecrets`: Enables migration of secrets and passwords
- `migratePermanentFiles`: Controls migration of permanent files
- `migrateTriggers`: Enables migration of triggers
- `migrateNotifications`: Enables migration of notifications
- `migrateStructureOnly`: When true, migrates only the project structure
- `migrateBuckets`: Enables migration of buckets
- `migrateTables`: Enables migration of tables
- `migrateProjectMetadata`: Enables migration of project metadata
- `skipRegionValidation`: Skips validation of regions during migration

#### Dry-run mode

If you want to save some time and check that everything is set correctly, you can use the dry-run
mode. Just set `configData.parameters.dryRun` on `true` in your request payload.

What is **not** executed during dry-run mode?

#### Project restore

- add project [metadata](https://github.com/keboola/php-kbc-project-restore/blob/65e461097541210227a31d3db16594f1524e4815/src/Restore.php#L74) into destination project
- add restored [configurations](https://github.com/keboola/php-kbc-project-restore/blob/65e461097541210227a31d3db16594f1524e4815/src/Restore.php#L157), its [rows](https://github.com/keboola/php-kbc-project-restore/blob/65e461097541210227a31d3db16594f1524e4815/src/Restore.php#L199), [metadata](https://github.com/keboola/php-kbc-project-restore/blob/65e461097541210227a31d3db16594f1524e4815/src/Restore.php#L266), [state](https://github.com/keboola/php-kbc-project-restore/blob/65e461097541210227a31d3db16594f1524e4815/src/Restore.php#L173) and [row order](https://github.com/keboola/php-kbc-project-restore/blob/65e461097541210227a31d3db16594f1524e4815/src/Restore.php#L236) into destination project
- create [buckets](https://github.com/keboola/php-kbc-project-restore/blob/65e461097541210227a31d3db16594f1524e4815/src/Restore.php#L308) and its [metadata](https://github.com/keboola/php-kbc-project-restore/blob/65e461097541210227a31d3db16594f1524e4815/src/Restore.php#L328) in destination project
- create [tables](https://github.com/keboola/php-kbc-project-restore/blob/65e461097541210227a31d3db16594f1524e4815/src/Restore.php#L487), [table aliases and metadata](https://github.com/keboola/php-kbc-project-restore/blob/65e461097541210227a31d3db16594f1524e4815/src/Restore.php#L439) in destination project

#### Migrate configurations (via `encryption-api`)

- add migrated configurations, its rows, metadata, state and row order into destination project

#### Migrate Snowflake writers

- create [workspace](https://github.com/keboola/app-snowflake-writer-migrate/blob/ee8ef0fa341e863bdb6f683424f764b2e5d0e6aa/src/MigrateWriter.php#L74) for destination project (Keboola-provisioned writers)
- add migrated [configurations](https://github.com/keboola/app-snowflake-writer-migrate/blob/ee8ef0fa341e863bdb6f683424f764b2e5d0e6aa/src/MigrateWriter.php#L95) and its [rows](https://github.com/keboola/app-snowflake-writer-migrate/blob/ee8ef0fa341e863bdb6f683424f764b2e5d0e6aa/src/MigrateWriter.php#L117)

#### Migrate tables data

- default (API) mode:
  - [file upload](https://github.com/keboola/app-project-migrate-tables-data/blob/88625047c4e6974fc556a2ff0eabdbfbf16b2c51/src/Strategy/SapiMigrate.php#L74) into destination project
  - [write data](https://github.com/keboola/app-project-migrate-tables-data/blob/88625047c4e6974fc556a2ff0eabdbfbf16b2c51/src/Strategy/SapiMigrate.php#L96) into destination tables
- database mode:
  - [replicate tables](https://github.com/keboola/app-project-migrate-tables-data/blob/88625047c4e6974fc556a2ff0eabdbfbf16b2c51/src/Strategy/DatabaseMigrate.php#L109)

## Development
 
Clone this repository and init the workspace with following command:

```shell
git clone https://github.com/keboola/my-component
cd my-component
docker-compose build
docker-compose run --rm dev composer install --no-scripts
```

Run the test suite using this command:

```shell
docker-compose run --rm dev composer tests
```
 
# Integration

For information about deployment and integration with KBC, please refer to the [deployment section of developers documentation](https://developers.keboola.com/extend/component/deployment/) 

## License

MIT licensed, see [LICENSE](./LICENSE) file.
