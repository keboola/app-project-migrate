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
    public const DATA_GATEWAY_COMPONENT = 'keboola.app-data-gateway';

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
        /** @var string|null $value */
        $value = $this->getValue(['parameters', '#sourceManageToken']);
        return is_string($value) ? $value : null;
    }

    public function getMigrateDataMode(): string
    {
        return $this->getStringValue(['parameters', 'dataMode']);
    }

    /**
     * @return array{
     *     host: string,
     *     username: string,
     *     "#password"?: string,
     *     "#privateKey"?: string,
     *     warehouse: string,
     *     warehouse_size?: 'SMALL'|'MEDIUM'|'LARGE'
     * }|array{}
     */
    public function getDb(): array
    {
        return $this->getArrayValue(['parameters', 'db'], []);
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

    public function shouldMigrateDataGateway(): bool
    {
        return $this->getBoolValue(['parameters', 'migrateDataGateway'], true);
    }

    public function shouldMigrateBuckets(): bool
    {
        return $this->getBoolValue(['parameters', 'migrateBuckets']);
    }

    public function shouldMigrateTables(): bool
    {
        return $this->getBoolValue(['parameters', 'migrateTables']);
    }

    public function shouldMigrateProjectMetadata(): bool
    {
        return $this->getBoolValue(['parameters', 'migrateProjectMetadata']);
    }

    public function isSourceByodb(): bool
    {
        return $this->getBoolValue(['parameters', 'isSourceByodb']);
    }

    public function getSourceByodb(): string
    {
        return $this->getStringValue(['parameters', 'sourceByodb'], '');
    }

    /**
     * @return array<int, string>
     */
    public function getIncludeWorkspaceSchemas(): array
    {
        /** @var array<int, string> $value */
        $value = $this->getArrayValue(['parameters', 'includeWorkspaceSchemas'], []);
        return $value;
    }

    public function preserveTimestamp(): bool
    {
        return $this->getBoolValue(['parameters', 'preserveTimestamp']);
    }

    public function isForcePrimaryKeyNotNull(): bool
    {
        return $this->getBoolValue(['parameters', 'forcePrimaryKeyNotNull']);
    }

    public function getTableParallelism(): ?int
    {
        $value = $this->getValue(['parameters', 'tableParallelism']);
        return is_int($value) ? $value : null;
    }

    public function getGcsLargeTableParallelChunks(): int
    {
        $value = $this->getValue(['parameters', 'gcsLargeTable', 'parallelChunks'], 3);
        return is_int($value) ? $value : 3;
    }

    public function getGcsLargeTableChunkSize(): int
    {
        $value = $this->getValue(['parameters', 'gcsLargeTable', 'chunkSize'], 150);
        return is_int($value) ? $value : 150;
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
            return $this->getStringValue(['parameters', 'componentsDevTag', 'tablesData']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string> $keys
     */
    public function getBoolValue(array $keys, bool $default = false): bool
    {
        return (bool) $this->getValue($keys, $default);
    }
}
