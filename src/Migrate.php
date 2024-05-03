<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Closure;
use Keboola\AppProjectMigrate\JobRunner\JobRunner;
use Keboola\AppProjectMigrate\JobRunner\SyrupJobRunner;
use Keboola\Component\UserException;
use Keboola\EncryptionApiClient\Exception\ClientException as EncryptionClientException;
use Keboola\EncryptionApiClient\Migrations;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\Syrup\ClientException as SyrupClientException;
use Psr\Log\LoggerInterface;

class Migrate
{
    private const JOB_STATUS_SUCCESS = 'success';

    private JobRunner $sourceJobRunner;

    private JobRunner $destJobRunner;

    private string $sourceProjectUrl;

    private string $sourceProjectToken;

    private string $destinationProjectUrl;

    private string $destinationProjectToken;

    private ?string $manageApiToken;

    private LoggerInterface $logger;

    private bool $directDataMigration;

    private bool $migrateSecrets;

    private Closure $sourceClientFactory;

    private Closure $migrationsClientFactory;

    public function __construct(
        Config $config,
        JobRunner $sourceJobRunner,
        JobRunner $destJobRunner,
        string $destinationProjectUrl,
        string $destinationProjectToken,
        LoggerInterface $logger
    ) {
        $this->sourceJobRunner = $sourceJobRunner;
        $this->destJobRunner = $destJobRunner;
        $this->sourceProjectUrl = $config->getSourceProjectUrl();
        $this->sourceProjectToken = $config->getSourceProjectToken();
        $this->destinationProjectUrl = $destinationProjectUrl;
        $this->destinationProjectToken = $destinationProjectToken;
        $this->manageApiToken = $config->getManageToken();
        $this->directDataMigration = $config->directDataMigration();
        $this->migrateSecrets = $config->shouldMigrateSecrets();
        $this->logger = $logger;
    }

    public function run(): void
    {
        $restoreCredentials = $this->generateBackupCredentials();
        try {
            $this->backupSourceProject($restoreCredentials['backupId']);
            $this->restoreDestinationProject($restoreCredentials);

            if ($this->migrateSecrets) {
                $this->migrateSecrets();
            }

            if ($this->directDataMigration) {
                $this->migrateDataOfTablesDirectly();
            }

            if (!$this->migrateSecrets) {
                // We want to migrate Snowflake writers only if we are not migrating secrets, because when migrating
                // secrets, Snowflake writers will be migrated by the encryption-api.
                $this->migrateSnowflakeWriters();
            }
            if ($this->sourceJobRunner instanceof SyrupJobRunner) {
                $this->migrateOrchestrations();
            }
        } catch (SyrupClientException|EncryptionClientException $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                throw new UserException($e->getMessage(), $e->getCode(), $e);
            }
        }
    }

    public function setSourceClientFactory(callable $factory): void
    {
        $this->sourceClientFactory = Closure::fromCallable($factory);
    }

    public function setMigrationsClientFactory(callable $factory): void
    {
        $this->migrationsClientFactory = Closure::fromCallable($factory);
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

        $job = $this->destJobRunner->runJob(
            Config::PROJECT_RESTORE_COMPONENT,
            $this->getRestoreConfigData($restoreCredentials)
        );

        if ($job['status'] !== self::JOB_STATUS_SUCCESS) {
            throw new UserException('Project restore error: ' . $job['result']['message']);
        }
        $this->logger->info('Current project restored');
    }

    private function migrateSecrets(): void
    {
        $this->logger->info('Migrating secrets in configurations');

        $sourceClient = $this->createSourceClient();

        $sourceDevBranches = new DevBranches($sourceClient);
        $sourceBranches = $sourceDevBranches->listBranches();
        $defaultSourceBranch = current(array_filter($sourceBranches, fn($b) => $b['isDefault'] === true));

        $sourceComponentsApi = new Components($sourceClient);
        $components = $sourceComponentsApi->listComponents();
        if (!$components) {
            $this->logger->info('There are no components to migrate.');
            return;
        }

        $encryptionApiUrl = $sourceClient->getServiceUrl('encryption');
        $migrations = $this->createMigrationsClient($encryptionApiUrl);

        foreach ($components as $component) {
            foreach ($component['configurations'] as $config) {
                $response = $migrations
                    ->migrateConfiguration(
                        $this->sourceProjectToken,
                        Utils::getStackFromProjectUrl($this->destinationProjectUrl),
                        $this->destinationProjectToken,
                        $component['id'],
                        $config['id'],
                        $defaultSourceBranch['id'],
                    );

                $this->logger->debug($response['message']);
            }
        }

        $this->logger->info('Secrets in configurations have been migrated.');
    }

    private function createSourceClient(): StorageClient
    {
        if (isset($this->sourceClientFactory)) {
            return (fn(): StorageClient => ($this->sourceClientFactory)())();
        }
        return new StorageClient(['token' => $this->sourceProjectToken, 'url' => $this->sourceProjectUrl ]);
    }

    private function createMigrationsClient(string $encryptionApiUrl): Migrations
    {
        if (isset($this->migrationsClientFactory)) {
            return (fn(): Migrations => ($this->migrationsClientFactory)($encryptionApiUrl))();
        }
        if (!$this->manageApiToken) {
            throw new UserException('#manageToken must be set', 422);
        }
        return new Migrations($this->manageApiToken, [
            'url' => $encryptionApiUrl,
        ]);
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
                    'restoreConfigs' => $this->migrateSecrets === false,
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
                    'restoreConfigs' => $this->migrateSecrets === false,
                ],
            ];
        } else {
            throw new UserException('Unrecognized restore credentials.');
        }
    }
}
