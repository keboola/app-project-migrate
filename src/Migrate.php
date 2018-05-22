<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Syrup\Client as SyrupClient;

class Migrate
{
    public const PROJECT_BACKUP_COMPONENT = 'keboola.project-backup';
    public const PROJECT_RESTORE_COMPONENT = 'keboola.project-restore';

    /** @var SyrupClient */
    private $sourceProjectClient;

    /** @var SyrupClient */
    private $destProjectClient;

    public function __construct(
        SyrupClient $sourceProjectClient,
        SyrupClient $destProjectClient
    ) {
        $this->sourceProjectClient = $sourceProjectClient;
        $this->destProjectClient = $destProjectClient;
    }

    public function run(): void
    {
        $restoreCredentials = $this->generateBackupCredentials();

        $this->backupSourceProject($restoreCredentials['backupId']);
        $this->restoreDestinationProject($restoreCredentials);
    }

    private function generateBackupCredentials(): array
    {
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
        $this->sourceProjectClient->runJob(
            self::PROJECT_BACKUP_COMPONENT,
            [
                'configData' => [
                    'parameters' => [
                        'backupId' => $backupId,
                    ],
                ],
            ]
        );
    }

    private function restoreDestinationProject(array $restoreCredentials): void
    {
        $this->destProjectClient->runJob(
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
    }
}
