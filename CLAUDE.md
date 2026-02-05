# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

App Project Migrate is a Keboola Connection orchestration application that migrates projects between stacks (e.g., US to EU). It coordinates multiple specialized migration applications to transfer configurations, data, secrets, and infrastructure.

The application runs in the **destination** project and reads from the **source** project (which remains unchanged).

## Build & Test Commands

```bash
# Full build pipeline (lint → phpcs → phpstan → tests)
composer build

# Individual commands
composer tests              # Run all tests (phpunit + datadir)
composer tests-phpunit      # Unit tests only (tests/phpunit/)
composer tests-datadir      # Functional tests only (tests/functional/)
composer phpstan            # Static analysis (max level)
composer phpcs              # Code style check
composer phpcbf             # Auto-fix code style

# Docker development
docker-compose build
docker-compose run --rm dev composer install --no-scripts
docker-compose run --rm dev composer tests
```

## Architecture

### Core Flow

1. **Component** (`src/Component.php`) - Entry point, validates tokens and project state
2. **Migrate** (`src/Migrate.php`) - Orchestrates migration steps in sequence
3. **JobRunner** - Executes jobs via appropriate queue system (QueueV2 or legacy Syrup)
4. **AfterMigration** (`src/Checker/AfterMigration.php`) - Post-migration validation

### Migration Steps (in order)

1. Backup source project (keboola.project-backup)
2. Restore to destination (keboola.project-restore)
3. Migrate secrets/configs via encryption-api (if enabled)
4. Migrate table data directly (keboola.app-project-migrate-large-tables)
5. Migrate Snowflake writers and Orchestrators (dedicated apps)

### Key Patterns

- **JobRunner Factory**: Creates QueueV2JobRunner or SyrupJobRunner based on project features
- **Configuration**: ConfigDefinition uses Symfony Config for validation; secrets prefixed with `#`
- **Data Modes**: `sapi` (API-based transfer) or `database` (direct DB replication)

### Configuration Parameters

Required: `sourceKbcUrl`, `#sourceKbcToken`
Optional: `migrateSecrets`, `migrateBuckets`, `migrateTables`, `dryRun`, `dataMode`, etc.

When `migrateSecrets=true`: requires `#sourceManageToken`
When `dataMode=database`: requires `db` object with credentials

## Code Standards

- PHP 7.4 with strict types
- PHPStan level max
- Keboola coding standard (PSR-12 based)
- UserException for user-actionable errors
