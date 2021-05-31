<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Component\UserException;
use Keboola\Syrup\ClientException;
use Psr\Log\LoggerInterface;

class Migrate
{
    public const PROJECT_BACKUP_COMPONENT = 'keboola.project-backup';
    public const PROJECT_RESTORE_COMPONENT = 'keboola.project-restore';
    public const ORCHESTRATOR_MIGRATE_COMPONENT = 'keboola.app-orchestrator-migrate';
    public const SNOWFLAKE_WRITER_MIGRATE_COMPONENT = 'keboola.app-snowflake-writer-migrate';

    private const JOB_STATUS_SUCCESS = 'success';

    /** @var DockerRunnerClient */
    private $sourceProjectClient;

    /** @var DockerRunnerClient */
    private $destProjectClient;

    /** @var string */
    private $sourceProjectUrl;

    /** @var string */
    private $sourceProjectToken;

    /** @var LoggerInterface  */
    private $logger;

    public function __construct(
        DockerRunnerClient $sourceProjectClient,
        DockerRunnerClient $destProjectClient,
        string $sourceProjectUrl,
        string $sourceProjectToken,
        LoggerInterface $logger
    ) {
        $this->sourceProjectClient = $sourceProjectClient;
        $this->destProjectClient = $destProjectClient;
        $this->sourceProjectUrl = $sourceProjectUrl;
        $this->sourceProjectToken = $sourceProjectToken;
        $this->logger = $logger;
    }

    public function run(): void
    {
        $restoreCredentials = $this->generateBackupCredentials();
        try {
            $this->backupSourceProject($restoreCredentials['backupId']);
            $this->restoreDestinationProject($restoreCredentials);
            $this->migrateSnowflakeWriters();
            $this->migrateOrchestrations();
        } catch (ClientException $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                throw new UserException($e->getMessage(), $e->getCode(), $e);
            }
        }
    }

    private function generateBackupCredentials(): array
    {
        $this->logger->info('Creating backup credentials');
        return $this->sourceProjectClient->runSyncAction(
            self::PROJECT_BACKUP_COMPONENT,
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
        $job = $this->sourceProjectClient->runJob(
            self::PROJECT_BACKUP_COMPONENT,
            [
                'configData' => [
                    'parameters' => [
                        'backupId' => $backupId,
                    ],
                ],
            ]
        );
        if ($job['status'] !== self::JOB_STATUS_SUCCESS) {
            throw new UserException('Project snapshot create error: ' . $job['result']['message']);
        }
        $this->logger->info('Source project snapshot created');
    }

    private function restoreDestinationProject(array $restoreCredentials): void
    {
        $this->logger->info('Restoring current project from snapshot');
        $job = $this->destProjectClient->runJob(
            self::PROJECT_RESTORE_COMPONENT,
            [
                'configData' => $this->getRestoreConfigData($restoreCredentials),
            ]
        );
        if ($job['status'] !== self::JOB_STATUS_SUCCESS) {
            throw new UserException('Project restore error: ' . $job['result']['message']);
        }
        $this->logger->info('Current project restored');
    }

    private function migrateOrchestrations(): void
    {
        $this->logger->info('Migrating orchestrations');
        $job = $this->destProjectClient->runJob(
            self::ORCHESTRATOR_MIGRATE_COMPONENT,
            [
                'configData' => [
                    'parameters' => [
                        'sourceKbcUrl' => $this->sourceProjectUrl,
                        '#sourceKbcToken' => $this->sourceProjectToken,
                    ],
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
        $job = $this->destProjectClient->runJob(
            self::SNOWFLAKE_WRITER_MIGRATE_COMPONENT,
            [
                'configData' => [
                    'parameters' => [
                        'sourceKbcUrl' => $this->sourceProjectUrl,
                        '#sourceKbcToken' => $this->sourceProjectToken,
                    ],
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
