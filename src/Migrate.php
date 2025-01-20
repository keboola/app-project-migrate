<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\AppProjectMigrate\JobRunner\JobRunner;
use Keboola\AppProjectMigrate\JobRunner\SyrupJobRunner;
use Keboola\Component\UserException;
use Keboola\EncryptionApiClient\Exception\ClientException as EncryptionClientException;
use Keboola\EncryptionApiClient\Migrations as MigrationsClient;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Syrup\ClientException as SyrupClientException;
use Psr\Log\LoggerInterface;

class Migrate
{
    private const JOB_STATUS_SUCCESS = 'success';

    private JobRunner $sourceJobRunner;

    private JobRunner $destJobRunner;

    private StorageClient $sourceProjectStorageClient;

    private StorageClient $destProjectStorageClient;

    private MigrationsClient $migrationsClient;

    private string $sourceProjectUrl;

    private string $sourceProjectToken;

    private string $destinationProjectUrl;

    private string $destinationProjectToken;

    private LoggerInterface $logger;

    private bool $dryRun;

    private bool $directDataMigration;

    private bool $migrateSecrets;

    private bool $migratePermanentFiles;

    private bool $migrateStructureOnly;

    public const OBSOLETE_COMPONENTS = [
        'orchestrator',
        'gooddata-writer',
    ];

    public const SNOWFLAKE_WRITER_COMPONENT_IDS = [
        'keboola.wr-db-snowflake', // aws
        'keboola.wr-snowflake-blob-storage', // azure
        'keboola.wr-db-snowflake-gcs', // gcp
        'keboola.wr-db-snowflake-gcs-s3', // gcp with s3
    ];

    private string $migrateDataMode;

    private array $db;

    private array $migratedSnowflakeWorkspaces = [];

    public function __construct(
        Config $config,
        JobRunner $sourceJobRunner,
        JobRunner $destJobRunner,
        StorageClient $sourceProjectStorageClient,
        StorageClient $destProjectStorageClient,
        MigrationsClient $migrationsClient,
        string $destinationProjectUrl,
        string $destinationProjectToken,
        LoggerInterface $logger
    ) {
        $this->sourceJobRunner = $sourceJobRunner;
        $this->destJobRunner = $destJobRunner;
        $this->sourceProjectStorageClient = $sourceProjectStorageClient;
        $this->destProjectStorageClient = $destProjectStorageClient;
        $this->migrationsClient = $migrationsClient;
        $this->sourceProjectUrl = $config->getSourceProjectUrl();
        $this->sourceProjectToken = $config->getSourceProjectToken();
        $this->destinationProjectUrl = $destinationProjectUrl;
        $this->destinationProjectToken = $destinationProjectToken;
        $this->dryRun = $config->isDryRun();
        $this->directDataMigration = $config->directDataMigration();
        $this->migrateSecrets = $config->shouldMigrateSecrets();
        $this->migratePermanentFiles = $config->shouldMigratePermanentFiles();
        $this->migrateStructureOnly = $config->shouldMigrateStructureOnly();
        $this->logger = $logger;
        $this->migrateDataMode = $config->getMigrateDataMode();
        $this->db = $config->getDb();
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

            if ($this->directDataMigration && !$this->migrateStructureOnly) {
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
            throw $e;
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
                    'exportStructureOnly' => $this->directDataMigration || $this->migrateStructureOnly,
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

        $configData = $this->getRestoreConfigData($restoreCredentials);
        $configData['parameters']['dryRun'] = $this->dryRun;

        $job = $this->destJobRunner->runJob(
            Config::PROJECT_RESTORE_COMPONENT,
            $configData
        );

        if ($job['status'] !== self::JOB_STATUS_SUCCESS) {
            throw new UserException('Project restore error: ' . $job['result']['message']);
        }
        $this->logger->info('Current project restored');
    }

    private function migrateSecrets(): void
    {
        $this->logger->info('Migrating configurations with secrets');

        $sourceDevBranches = new DevBranches($this->sourceProjectStorageClient);
        $sourceBranches = $sourceDevBranches->listBranches();
        $defaultSourceBranch = current(array_filter($sourceBranches, fn($b) => $b['isDefault'] === true));

        $sourceComponentsApi = new Components($this->sourceProjectStorageClient);
        $components = $sourceComponentsApi->listComponents();
        if (!$components) {
            $this->logger->info('There are no components to migrate.');
            return;
        }

        foreach ($components as $component) {
            if (in_array($component['id'], self::OBSOLETE_COMPONENTS, true)) {
                $this->logger->info('Components "{componentId}" is obsolete, skipping migration...', [
                    'componentId' => $component['id'],
                ]);
                continue;
            }

            foreach ($component['configurations'] as $config) {
                $this->logger->info(
                    sprintf(
                        '%sMigrating configuration "{configId}" of component "{componentId}"',
                        $this->dryRun ? '[dry-run] ' : '',
                    ),
                    [
                        'configId' => $config['id'],
                        'componentId' => $component['id'],
                    ],
                );

                try {
                    $response = $this->migrationsClient
                        ->migrateConfiguration(
                            $this->sourceProjectToken,
                            Utils::getStackFromProjectUrl($this->destinationProjectUrl),
                            $this->destinationProjectToken,
                            $component['id'],
                            $config['id'],
                            (string) $defaultSourceBranch['id'],
                            $this->dryRun
                        );
                } catch (EncryptionClientException $e) {
                    $this->logger->error(
                        'Migrating configuration "{configId}" of component "{componentId}" failed: {message}',
                        [
                            'configId' => $config['id'],
                            'componentId' => $component['id'],
                            'message' => $e->getMessage(),
                            'exception' => $e,
                        ],
                    );
                    continue;
                }

                if (in_array($component['id'], self::SNOWFLAKE_WRITER_COMPONENT_IDS, true)) {
                    $this->preserveProperSnowflakeWorkspace(
                        $component['id'],
                        $config['id'],
                        $response['data']['componentId'],
                        $response['data']['configId']
                    );
                }

                $message = $response['message'];
                if ($this->dryRun) {
                    $message = '[dry-run] ' . $message;
                }

                $this->logger->info($message);

                if (isset($response['warnings']) && is_array($response['warnings'])) {
                    foreach ($response['warnings'] as $warning) {
                        $this->logger->warning($warning);
                    }
                }
            }
        }
    }

    private function migrateDataOfTablesDirectly(): void
    {
        $this->logger->info('Migrate data of tables directly.');

        $parameters = [
            'mode' => $this->migrateDataMode,
            'sourceKbcUrl' => $this->sourceProjectUrl,
            '#sourceKbcToken' => $this->sourceProjectToken,
            'dryRun' => $this->dryRun,
        ];

        if ($this->migrateDataMode === 'database' && !empty($this->db)) {
            $parameters['db'] = $this->db;
        }

        $this->destJobRunner->runJob(
            Config::DATA_OF_TABLES_MIGRATE_COMPONENT,
            [
                'parameters' => $parameters,
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
                    'dryRun' => $this->dryRun,
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
                    'restorePermanentFiles' => $this->migratePermanentFiles,
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
                    'restorePermanentFiles' => $this->migratePermanentFiles,
                ],
            ];
        } else {
            throw new UserException('Unrecognized restore credentials.');
        }
    }

    private function preserveProperSnowflakeWorkspace(
        string $sourceComponentId,
        string $sourceConfigurationId,
        string $destinationComponentId,
        string $destinationConfigurationId
    ): void {
        if ($this->dryRun) {
            return;
        }
        $sourceComponentsApi = new Components($this->sourceProjectStorageClient);
        $sourceConfigurationData = (array) $sourceComponentsApi
            ->getConfiguration($sourceComponentId, $sourceConfigurationId);

        $destinationComponentsApi = new Components($this->destProjectStorageClient);
        $destinationConfigurationData = (array) $destinationComponentsApi
            ->getConfiguration($destinationComponentId, $destinationConfigurationId);

        $snowflakeUser = $sourceConfigurationData['configuration']['parameters']['db']['user'];
        $migratedWorkspaceParameters = $this->migratedSnowflakeWorkspaces[$snowflakeUser] ?? null;

        if ($migratedWorkspaceParameters) {
            // Use the existing Snowflake workspace from a previous configuration that has the same source workspace
            $destinationConfigurationData['configuration']['parameters']['db'] = $migratedWorkspaceParameters;

            $destinationConfiguration = (new Configuration())
                ->setConfigurationId($destinationConfigurationId)
                ->setComponentId($destinationComponentId)
                ->setName($destinationConfigurationData['name'])
                ->setDescription($destinationConfigurationData['description'])
                ->setIsDisabled($destinationConfigurationData['isDisabled'])
                ->setConfiguration($destinationConfigurationData['configuration']);

            $destinationComponentsApi->updateConfiguration($destinationConfiguration);

            $this->logger->info('Used existing Snowflake workspace "{workspace}" '
                . 'for configuration with ID "{configId}" ({componentId}).', [
                'workspace' => $migratedWorkspaceParameters['user'],
                'configId' => $destinationConfigurationId,
                'componentId' => $destinationComponentId,
            ]);
            return;
        }

        // Store Snowflake workspace for next configurations
        $workspaceParameters = $destinationConfigurationData['configuration']['parameters']['db'];
        $this->migratedSnowflakeWorkspaces[$snowflakeUser] = $workspaceParameters;
    }
}
