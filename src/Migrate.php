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

    private bool $migrateTriggers;

    private bool $migrateNotifications;

    private bool $migrateBuckets;

    private bool $migrateTables;

    private bool $migrateProjectMetadata;

    private bool $migrateStructureOnly;

    private bool $skipRegionValidation;

    private bool $isSourceByodb;

    private bool $checkEmptyProject;

    private string $sourceByodb;

    private array $includeWorkspaceSchemas;

    private bool $preserveTimestamp;

    private ?string $appBackupTag = null;

    private ?string $appRestoreTag = null;

    private ?string $appTablesDataTag = null;

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
        $this->migrateTriggers = $config->shouldMigrateTriggers();
        $this->migrateNotifications = $config->shouldMigrateNotifications();
        $this->migrateStructureOnly = $config->shouldMigrateStructureOnly();
        $this->migrateBuckets = $config->shouldMigrateBuckets();
        $this->migrateTables = $config->shouldMigrateTables();
        $this->migrateProjectMetadata = $config->shouldMigrateProjectMetadata();
        $this->skipRegionValidation = $config->shouldSkipRegionValidation();
        $this->isSourceByodb = $config->isSourceByodb();
        $this->sourceByodb = $config->getSourceByodb();
        $this->includeWorkspaceSchemas = $config->getIncludeWorkspaceSchemas();
        $this->preserveTimestamp = $config->preserveTimestamp();
        $this->checkEmptyProject = $config->checkEmptyProject();
        $this->logger = $logger;
        $this->migrateDataMode = $config->getMigrateDataMode();
        $this->db = $config->getDb();
        $this->appBackupTag = $config->getAppBackupTag();
        $this->appRestoreTag = $config->getAppRestoreTag();
        $this->appTablesDataTag = $config->getAppTablesDataTag();
    }

    public function run(): void
    {
        try {
            $backupId = (string) $this->sourceProjectStorageClient->generateId();
            $this->backupSourceProject($backupId);
            $restoreCredentials = $this->generateBackupCredentials($backupId);

            $this->restoreDestinationProject($restoreCredentials);

            if ($this->migrateSecrets) {
                $this->migrateSecrets();
            }

            if ($this->migrateBuckets &&
                $this->migrateTables &&
                $this->directDataMigration &&
                !$this->migrateStructureOnly
            ) {
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

    private function generateBackupCredentials(string $backupId): array
    {
        $this->logger->info('Creating backup credentials');

        return $this->sourceJobRunner->runSyncAction(
            Config::PROJECT_BACKUP_COMPONENT,
            'generate-read-credentials',
            [
                'parameters' => [
                    'backupId' => $backupId,
                    'skipRegionValidation' => $this->skipRegionValidation,
                ],
            ],
            $this->appBackupTag,
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
                    'skipRegionValidation' => $this->skipRegionValidation,
                ],
            ],
            $this->appBackupTag,
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
            $configData,
            $this->appRestoreTag,
        );

        if ($job['status'] !== self::JOB_STATUS_SUCCESS) {
            throw new UserException('Project restore error: ' . $job['result']['message']);
        }
        $this->logger->info('Current project restored');
    }

    private function migrateSecrets(): void
    {
        $this->logger->info('Migrating configurations with secrets', ['secrets']);

        $sourceDevBranches = new DevBranches($this->sourceProjectStorageClient);
        $sourceBranches = $sourceDevBranches->listBranches();
        $defaultSourceBranch = current(array_filter($sourceBranches, fn($b) => $b['isDefault'] === true));

        $sourceComponentsApi = new Components($this->sourceProjectStorageClient);
        $components = $sourceComponentsApi->listComponents();
        if (!$components) {
            $this->logger->info('There are no components to migrate.', ['secrets']);
            return;
        }

        foreach ($components as $component) {
            if (in_array($component['id'], self::OBSOLETE_COMPONENTS, true)) {
                $this->logger->info(
                    sprintf('Components "%s" is obsolete, skipping migration...', $component['id']),
                    ['secrets']
                );
                continue;
            }

            foreach ($component['configurations'] as $config) {
                $this->logger->info(
                    sprintf(
                        '%sMigrating configuration "%s" of component "%s"',
                        $this->dryRun ? '[dry-run] ' : '',
                        $config['id'],
                        $component['id'],
                    ),
                    ['secrets'],
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
                        sprintf(
                            'Migrating configuration "%s" of component "%s" failed: %s',
                            $config['id'],
                            $component['id'],
                            $e->getMessage()
                        ),
                        [
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

                $this->logger->info($message, ['secrets']);

                if (isset($response['warnings']) && is_array($response['warnings'])) {
                    foreach ($response['warnings'] as $warning) {
                        $this->logger->warning($warning, ['secrets']);
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
            'isSourceByodb' => $this->isSourceByodb,
            'sourceByodb' => $this->sourceByodb,
            'includeWorkspaceSchemas' => $this->includeWorkspaceSchemas,
            'preserveTimestamp' => $this->preserveTimestamp,
        ];

        if ($this->migrateDataMode === 'database' && !empty($this->db)) {
            $parameters['db'] = $this->db;
        }

        $this->destJobRunner->runJob(
            Config::DATA_OF_TABLES_MIGRATE_COMPONENT,
            [
                'parameters' => $parameters,
            ],
            $this->appTablesDataTag,
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
            ],
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
                    'restoreTriggers' => $this->migrateTriggers,
                    'restoreNotifications' => $this->migrateNotifications,
                    'restoreBuckets' => $this->migrateBuckets,
                    'restoreTables' => $this->migrateTables,
                    'restoreProjectMetadata' => $this->migrateProjectMetadata,
                    'checkEmptyProject' => $this->checkEmptyProject,
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
                    'restoreTriggers' => $this->migrateTriggers,
                    'restoreNotifications' => $this->migrateNotifications,
                    'restoreBuckets' => $this->migrateBuckets,
                    'restoreTables' => $this->migrateTables,
                    'restoreProjectMetadata' => $this->migrateProjectMetadata,
                    'checkEmptyProject' => $this->checkEmptyProject,
                ],
            ];
        } elseif (isset($restoreCredentials['credentials']['accessToken'])) {
            return [
                'parameters' => [
                    'gcs' => [
                        'projectId' => $restoreCredentials['projectId'],
                        'bucket' => $restoreCredentials['bucket'],
                        'backupUri' => $restoreCredentials['backupUri'],
                        'credentials' => [
                            '#accessToken' => $restoreCredentials['credentials']['accessToken'],
                            'expiresIn' => $restoreCredentials['credentials']['expiresIn'],
                            'tokenType' => $restoreCredentials['credentials']['tokenType'],
                        ],
                    ],
                    'useDefaultBackend' => true,
                    'restoreConfigs' => $this->migrateSecrets === false,
                    'restorePermanentFiles' => $this->migratePermanentFiles,
                    'restoreTriggers' => $this->migrateTriggers,
                    'restoreNotifications' => $this->migrateNotifications,
                    'restoreBuckets' => $this->migrateBuckets,
                    'restoreTables' => $this->migrateTables,
                    'restoreProjectMetadata' => $this->migrateProjectMetadata,
                    'checkEmptyProject' => $this->checkEmptyProject,
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

        $snowflakeUser = $sourceConfigurationData['configuration']['parameters']['db']['user'] ?? null;
        if ($snowflakeUser === null) {
            $this->logger->info(
                sprintf(
                    "Configuration with ID '%s' (%s) does not have a Snowflake workspace.",
                    $sourceConfigurationId,
                    $sourceComponentId,
                ),
                ['secrets']
            );
            return;
        }

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

            $this->logger->info(
                sprintf(
                    "Used existing Snowflake workspace '%s' for configuration with ID '%s' (%s).",
                    $migratedWorkspaceParameters['user'],
                    $destinationConfigurationId,
                    $destinationComponentId,
                ),
                ['secrets']
            );
            return;
        }

        // Store Snowflake workspace for next configurations
        $workspaceParameters = $destinationConfigurationData['configuration']['parameters']['db'];
        $this->migratedSnowflakeWorkspaces[$snowflakeUser] = $workspaceParameters;
    }
}
