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

    public function directDataMigration(): bool
    {
        return $this->getValue(['parameters', 'directDataMigration']);
    }

    public function exportStructureOnly(): bool
    {
        return $this->getValue(['parameters', 'exportStructureOnly']);
    }
}
