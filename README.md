# Project Migrate

[![Build Status](https://travis-ci.com/keboola/app-project-migrate.svg?branch=master)](https://travis-ci.com/keboola/app-project-migrate)

Application which orchestrates whole process of KBC project migration from one KBC stack to Another.

Prerequisites:
 - Source project which will be migrated
 - Destination project - empty project where the source project will be cloned
 
Application is executed in *destination* project and requires Storage API token and KBC url of *source* project.
Admin token of source project is required for GoodData writers migration.
Source project is left without any changes.

Migration steps performed by the application:

- Create snapshot of source project https://github.com/keboola/app-project-backup
- Restore project from snapshot https://github.com/keboola/app-project-restore
- Migrate GoodData writers https://github.com/keboola/app-gooddata-writer-migrate
- `TODO` Migrate Snowflake writers
- Migrate Orchestrators https://github.com/keboola/app-orchestrator-migrate


## Usage

Run the migration in destination project wil the following command.
This is example of project migration from US to EU, please replace these parameters:

- `DEST_PROJECT_SAPI_TOKEN` - Storage API token associated to admin of destination EU project
- `SOURCE_PROJECT_SAPI_TOKEN` - Storage API token associated to admin of source US project

```
curl -X POST \
 https://docker-runner.eu-central-1.keboola.com/docker/keboola.app-project-migrate/run \
 -H 'X-StorageApi-Token: DEST_PROJECT_SAPI_TOKEN' \
 -d '{"configData": {"parameters": {"sourceKbcUrl": "https://connection.keboola.com", "#sourceKbcToken":"SOURCE_PROJECT_SAPI_TOKEN"}}}'
```

## Development
 
Clone this repository and init the workspace with following command:

```
git clone https://github.com/keboola/my-component
cd my-component
docker-compose build
docker-compose run --rm dev composer install --no-scripts
```

Run the test suite using this command:

```
docker-compose run --rm dev composer tests
```
 
# Integration

For information about deployment and integration with KBC, please refer to the [deployment section of developers documentation](https://developers.keboola.com/extend/component/deployment/) 
