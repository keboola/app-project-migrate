<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Component\Config\BaseConfig;

class Config extends BaseConfig
{
    public const PROJECT_BACKUP_COMPONENT = 'keboola.project-backup';
    public const PROJECT_RESTORE_COMPONENT = 'keboola.project-restore';
    public const ORCHESTRATOR_MIGRATE_COMPONENT = 'keboola.app-orchestrator-migrate';
    public const SNOWFLAKE_WRITER_MIGRATE_COMPONENT = 'keboola.app-snowflake-writer-migrate';
    public const DATA_OF_TABLES_MIGRATE_COMPONENT = 'keboola.app-project-migrate-large-tables';

    public function getSourceProjectUrl(): string
    {
        return $this->getValue(['parameters', 'sourceKbcUrl']);
    }

    public function getSourceProjectToken(): string
    {
        return $this->getValue(['parameters', '#sourceKbcToken']);
    }

    public function isDryRun(): bool
    {
        return $this->getValue(['parameters', 'dryRun']);
    }

    public function directDataMigration(): bool
    {
        return $this->getValue(['parameters', 'directDataMigration']);
    }

    public function shouldMigrateSecrets(): bool
    {
        return $this->getValue(['parameters', 'migrateSecrets']);
    }

    public function getSourceManageToken(): ?string
    {
        return $this->getValue(['parameters', '#sourceManageToken']);
    }

    public function getMigrateDataMode(): string
    {
        return $this->getValue(['parameters', 'dataMode']);
    }

    public function getDb(): array
    {
        return (array) $this->getValue(['parameters', 'db'], []);
    }

    public function shouldMigratePermanentFiles(): bool
    {
        return $this->getValue(['parameters', 'migratePermanentFiles']);
    }

    public function shouldMigrateTriggers(): bool
    {
        return $this->getValue(['parameters', 'migrateTriggers']);
    }

    public function shouldMigrateNotifications(): bool
    {
        return $this->getValue(['parameters', 'migrateNotifications']);
    }

    public function shouldMigrateStructureOnly(): bool
    {
        return $this->getValue(['parameters', 'migrateStructureOnly']);
    }

    public function shouldSkipRegionValidation(): bool
    {
        return $this->getValue(['parameters', 'skipRegionValidation']);
    }

    public function shouldMigrateBuckets(): bool
    {
        /** @var bool $value */
        $value = $this->getValue(['parameters', 'migrateBuckets']);
        return $value;
    }

    public function shouldMigrateTables(): bool
    {
        /** @var bool $value */
        $value = $this->getValue(['parameters', 'migrateTables']);
        return $value;
    }

    public function shouldMigrateProjectMetadata(): bool
    {
        /** @var bool $value */
        $value = $this->getValue(['parameters', 'migrateProjectMetadata']);
        return $value;
    }

    public function isSourceByodb(): bool
    {
        return $this->getValue(['parameters', 'isSourceByodb']);
    }

    public function getSourceByodb(): string
    {
        return (string) $this->getValue(['parameters', 'sourceByodb'], '');
    }

    public function getIncludeWorkspaceSchemas(): array
    {
        $value = $this->getValue(['parameters', 'includeWorkspaceSchemas'], []);
        return empty($value) ? [] : (array) $value;
    }

    public function preserveTimestamp(): bool
    {
        return $this->getValue(['parameters', 'preserveTimestamp']);
    }

    public function checkEmptyProject(): bool
    {
        return $this->getValue(['parameters', 'checkEmptyProject']);
    }
}
