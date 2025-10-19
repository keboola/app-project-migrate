<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use InvalidArgumentException;
use Keboola\Component\Config\BaseConfig;

class Config extends BaseConfig
{
    public const PROJECT_BACKUP_COMPONENT = 'keboola.project-backup';
    public const PROJECT_RESTORE_COMPONENT = 'keboola.project-restore';
    public const SNOWFLAKE_WRITER_MIGRATE_COMPONENT = 'keboola.app-snowflake-writer-migrate';
    public const DATA_OF_TABLES_MIGRATE_COMPONENT = 'keboola.app-project-migrate-large-tables';

    public function getSourceProjectUrl(): string
    {
        return $this->getStringValue(['parameters', 'sourceKbcUrl']);
    }

    public function getSourceProjectToken(): string
    {
        return $this->getStringValue(['parameters', '#sourceKbcToken']);
    }

    public function isDryRun(): bool
    {
        return $this->getBoolValue(['parameters', 'dryRun']);
    }

    public function directDataMigration(): bool
    {
        return $this->getBoolValue(['parameters', 'directDataMigration']);
    }

    public function shouldMigrateSecrets(): bool
    {
        return $this->getBoolValue(['parameters', 'migrateSecrets']);
    }

    public function getSourceManageToken(): ?string
    {
        return $this->getValue(['parameters', '#sourceManageToken']);
    }

    public function getMigrateDataMode(): string
    {
        return $this->getStringValue(['parameters', 'dataMode']);
    }

    public function getDb(): array
    {
        return $this->getArrayValue(['parameters', 'db'], []);
    }

    public function shouldMigrateConfigurations(): bool
    {
        return $this->getBoolValue(['parameters', 'migrateConfigurations']);
    }

    public function shouldMigratePermanentFiles(): bool
    {
        return $this->getBoolValue(['parameters', 'migratePermanentFiles']);
    }

    public function shouldMigrateTriggers(): bool
    {
        return $this->getBoolValue(['parameters', 'migrateTriggers']);
    }

    public function shouldMigrateNotifications(): bool
    {
        return $this->getBoolValue(['parameters', 'migrateNotifications']);
    }

    public function shouldMigrateStructureOnly(): bool
    {
        return $this->getBoolValue(['parameters', 'migrateStructureOnly']);
    }

    public function shouldSkipRegionValidation(): bool
    {
        return $this->getBoolValue(['parameters', 'skipRegionValidation']);
    }

    public function shouldMigrateBuckets(): bool
    {
        /** @var bool $value */
        $value = $this->getBoolValue(['parameters', 'migrateBuckets']);
        return $value;
    }

    public function shouldMigrateTables(): bool
    {
        /** @var bool $value */
        $value = $this->getBoolValue(['parameters', 'migrateTables']);
        return $value;
    }

    public function shouldMigrateProjectMetadata(): bool
    {
        $value = $this->getBoolValue(['parameters', 'migrateProjectMetadata']);
        return $value;
    }

    public function getTablesToMigrate(): array
    {
        return $this->getArrayValue(['parameters', 'tablesToMigrate']);
    }

    public function getConfigurationsToMigrate(): array
    {
        return $this->getArrayValue(['parameters', 'configurationsToMigrate']);
    }

    public function isSourceByodb(): bool
    {
        return $this->getBoolValue(['parameters', 'isSourceByodb']);
    }

    public function getSourceByodb(): string
    {
        return $this->getStringValue(['parameters', 'sourceByodb'], '');
    }

    public function getIncludeWorkspaceSchemas(): array
    {
        $value = $this->getArrayValue(['parameters', 'includeWorkspaceSchemas'], []);
        return empty($value) ? [] : $value;
    }

    public function preserveTimestamp(): bool
    {
        return $this->getBoolValue(['parameters', 'preserveTimestamp']);
    }

    public function checkEmptyProject(): bool
    {
        return $this->getBoolValue(['parameters', 'checkEmptyProject']);
    }

    public function getAppBackupTag(): ?string
    {
        try {
            return $this->getStringValue(['parameters', 'componentsDevTag', 'backup']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function getAppRestoreTag(): ?string
    {
        try {
            return $this->getStringValue(['parameters', 'componentsDevTag', 'restore']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function getAppTablesDataTag(): ?string
    {
        try {
            return $this->getStringValue(['parameters', 'componentsDevTag', 'tables-data']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function getBoolValue(array $keys, mixed $default = null): bool
    {
        /** @var bool $value */
        $value = $this->getValue($keys, $default);
        return $value;
    }
}
