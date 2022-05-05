<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\AppProjectMigrate\JobRunner\JobRunner;
use Keboola\AppProjectMigrate\JobRunner\SyrupJobRunner;
use Keboola\Component\UserException;
use Keboola\Syrup\ClientException;
use Psr\Log\LoggerInterface;

class Migrate
{
    private const JOB_STATUS_SUCCESS = 'success';

    private JobRunner $sourceJobRunner;

    private JobRunner $destJobRunner;

    private string $sourceProjectUrl;

    private string $sourceProjectToken;

    private LoggerInterface $logger;

    private bool $directDataMigration;

    public function __construct(
        JobRunner $sourceJobRunner,
        JobRunner $destJobRunner,
        string $sourceProjectUrl,
        string $sourceProjectToken,
        bool $directDataMigration,
        LoggerInterface $logger
    ) {
        $this->sourceJobRunner = $sourceJobRunner;
        $this->destJobRunner = $destJobRunner;
        $this->sourceProjectUrl = $sourceProjectUrl;
        $this->sourceProjectToken = $sourceProjectToken;
        $this->directDataMigration = $directDataMigration;
        $this->logger = $logger;
    }

    public function run(): void
    {
        $restoreCredentials = $this->generateBackupCredentials();
        try {
            $this->backupSourceProject($restoreCredentials['backupId']);
            $this->restoreDestinationProject($restoreCredentials);

            if ($this->directDataMigration) {
                $this->migrateDataOfTablesDirectly();
            }

            $this->migrateSnowflakeWriters();
            if ($this->sourceJobRunner instanceof SyrupJobRunner) {
                $this->migrateOrchestrations();
            }
        } catch (ClientException $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                throw new UserException($e->getMessage(), $e->getCode(), $e);
            }
        }
    }

    private function generateBackupCredentials(): array
    {
        $this->logger->info('Creating backup credentials');
        return $this->sourceJobRunner->runSyncAction(
            Config::PROJECT_BACKUP_COMPONENT,
            'generate-read-credentials',
            [
                'parameters' => [
                    'backupId' => null,
                ],
            ]
        );
    }

    private function backupSourceProject(string $backupId): void
    {
        $this->logger->info('Creating source project snapshot');

        $job = $this->sourceJobRunner->runJob(
            Config::PROJECT_BACKUP_COMPONENT,
            [
                'parameters' => [
                    'backupId' => $backupId,
                    'exportStructureOnly' => $this->directDataMigration,
                ],
            ],
            'backupOnlyStorage.2'
        );
        if ($job['status'] !== self::JOB_STATUS_SUCCESS) {
            throw new UserException('Project snapshot create error: ' . $job['result']['message']);
        }
        $this->logger->info('Source project snapshot created');
    }

    private function restoreDestinationProject(array $restoreCredentials): void
    {
        $this->logger->info('Restoring current project from snapshot');

        $job = $this->destJobRunner->runJob(
            Config::PROJECT_RESTORE_COMPONENT,
            $this->getRestoreConfigData($restoreCredentials)
        );

        if ($job['status'] !== self::JOB_STATUS_SUCCESS) {
            throw new UserException('Project restore error: ' . $job['result']['message']);
        }
        $this->logger->info('Current project restored');
    }

    private function migrateDataOfTablesDirectly(): void
    {
        $this->logger->info('Migrate data of tables directly.');

        $this->destJobRunner->runJob(
            Config::DATA_OF_TABLES_MIGRATE_COMPONENT,
            [
                'parameters' => [
                    'sourceKbcUrl' => $this->sourceProjectUrl,
                    '#sourceKbcToken' => $this->sourceProjectToken,
                ],
            ]
        );

        $this->logger->info('Data of tables has been migrated.');
    }

    private function migrateOrchestrations(): void
    {
        $this->logger->info('Migrating orchestrations');

        $job = $this->destJobRunner->runJob(
            Config::ORCHESTRATOR_MIGRATE_COMPONENT,
            [
                'parameters' => [
                    'sourceKbcUrl' => $this->sourceProjectUrl,
                    '#sourceKbcToken' => $this->sourceProjectToken,
                ],
            ]
        );

        if ($job['status'] !== self::JOB_STATUS_SUCCESS) {
            throw new UserException('Orchestrations migration error: ' . $job['result']['message']);
        }
        $this->logger->info('Orchestrations migrated');
    }

    private function migrateSnowflakeWriters(): void
    {
        $this->logger->info('Migrating Snowflake writers');
        $job = $this->destJobRunner->runJob(
            Config::SNOWFLAKE_WRITER_MIGRATE_COMPONENT,
            [
                'parameters' => [
                    'sourceKbcUrl' => $this->sourceProjectUrl,
                    '#sourceKbcToken' => $this->sourceProjectToken,
                ],
            ]
        );

        if ($job['status'] !== self::JOB_STATUS_SUCCESS) {
            throw new UserException('Snowflake writers migration error: ' . $job['result']['message']);
        }
        $this->logger->info('Snowflake writers migrated');
    }

    private function getRestoreConfigData(array $restoreCredentials): array
    {
        if (isset($restoreCredentials['credentials']['secretAccessKey'])) {
            return [
                'parameters' => [
                    's3' => [
                        'backupUri' => $restoreCredentials['backupUri'],
                        'accessKeyId' => $restoreCredentials['credentials']['accessKeyId'],
                        '#secretAccessKey' => $restoreCredentials['credentials']['secretAccessKey'],
                        '#sessionToken' => $restoreCredentials['credentials']['sessionToken'],
                    ],
                    'useDefaultBackend' => true,
                ],
            ];
        } elseif (isset($restoreCredentials['credentials']['connectionString'])) {
            return [
                'parameters' => [
                    'abs' => [
                        'container' => $restoreCredentials['container'],
                        '#connectionString' => $restoreCredentials['credentials']['connectionString'],
                    ],
                    'useDefaultBackend' => true,
                ],
            ];
        } else {
            throw new UserException('Unrecognized restore credentials.');
        }
    }
}
