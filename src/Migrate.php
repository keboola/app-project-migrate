<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Component\UserException;
use Keboola\Syrup\Client as SyrupClient;
use Psr\Log\LoggerInterface;

class Migrate
{
    public const PROJECT_BACKUP_COMPONENT = 'keboola.project-backup';
    public const PROJECT_RESTORE_COMPONENT = 'keboola.project-restore';
    public const ORCHESTRATOR_MIGRATE_COMPONENT = 'keboola.app-orchestrator-migrate';
    public const GOOD_DATA_WRITER_MIGRATE_COMPONENT = 'keboola.app-gooddata-writer-migrate';

    /** @var SyrupClient */
    private $sourceProjectClient;

    /** @var SyrupClient */
    private $destProjectClient;

    /** @var string */
    private $sourceProjectUrl;

    /** @var string */
    private $sourceProjectToken;

    /** @var LoggerInterface  */
    private $logger;

    public function __construct(
        SyrupClient $sourceProjectClient,
        SyrupClient $destProjectClient,
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

        $this->backupSourceProject($restoreCredentials['backupId']);
        $this->restoreDestinationProject($restoreCredentials);
        $this->migrateGoodDataWriters();
        $this->migrateOrchestrations();
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
        if ($job['status'] !== 'success') {
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
                'configData' => [
                    'parameters' => [
                        'backupUri' => $restoreCredentials['backupUri'],
                        'accessKeyId' => $restoreCredentials['credentials']['accessKeyId'],
                        '#secretAccessKey' => $restoreCredentials['credentials']['secretAccessKey'],
                        '#sessionToken' => $restoreCredentials['credentials']['sessionToken'],
                        'useDefaultBackend' => true,
                    ],
                ],
            ]
        );
        if ($job['status'] !== 'success') {
            throw new UserException('Project restore error: ' . $job['result']['message']);
        }
        $this->logger->info('Current project restored');
    }

    private function migrateOrchestrations(): void
    {
        $this->logger->info('Migrating orchestrations');
        $this->destProjectClient->runJob(
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
        $this->logger->info('Orchestrations migrated');
    }

    private function migrateGoodDataWriters(): void
    {
        $this->logger->info('Migrating GoodData writers');
        $this->destProjectClient->runJob(
            self::GOOD_DATA_WRITER_MIGRATE_COMPONENT,
            [
                'configData' => [
                    'parameters' => [
                        'sourceKbcUrl' => $this->sourceProjectUrl,
                        '#sourceKbcToken' => $this->sourceProjectToken,
                    ],
                ],
            ]
        );
        $this->logger->info('GoodData writers migrated');
    }
}
