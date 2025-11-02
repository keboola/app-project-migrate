<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\AppProjectMigrate\JobRunner\JobRunner;
use Keboola\Component\UserException;
use Keboola\EncryptionApiClient\Exception\ClientException as EncryptionClientException;
use Keboola\EncryptionApiClient\Migrations as MigrationsClient;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\SyncActionsClient\Model\ActionResponse;
use Keboola\Syrup\ClientException as SyrupClientException;
use Psr\Log\LoggerInterface;

class Migrate
{
    private const JOB_STATUS_SUCCESS = 'success';
    private array $migratedSnowflakeWorkspaces = [];

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

    public function __construct(
        readonly Config $config,
        readonly JobRunner $sourceJobRunner,
        readonly JobRunner $destJobRunner,
        readonly StorageClient $sourceProjectStorageClient,
        readonly StorageClient $destProjectStorageClient,
        readonly MigrationsClient $migrationsClient,
        readonly string $destinationProjectUrl,
        readonly string $destinationProjectToken,
        readonly LoggerInterface $logger,
    ) {
    }

    public function run(): void
    {
        try {
            $backupId = $this->sourceProjectStorageClient->generateId();
            $this->backupSourceProject($backupId);

            $restoreCredentials = $this->generateBackupCredentials($backupId);
            $this->restoreDestinationProject($restoreCredentials);

            if ($this->config->shouldMigrateSecrets()) {
                $this->migrateSecrets();
            }

            if ($this->config->shouldMigrateBuckets() &&
                $this->config->shouldMigrateTables() &&
                $this->config->directDataMigration() &&
                !$this->config->shouldMigrateStructureOnly()
            ) {
                $this->migrateDataOfTablesDirectly();
            }

            if (!$this->config->shouldMigrateSecrets()) {
                // We want to migrate Snowflake writers only if we are not migrating secrets, because when migrating
                // secrets, Snowflake writers will be migrated by the encryption-api.
                $this->migrateSnowflakeWriters();
            }
        } catch (SyrupClientException|EncryptionClientException $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                throw new UserException($e->getMessage(), $e->getCode(), $e);
            }
            throw $e;
        }
    }

    private function generateBackupCredentials(string $backupId): ActionResponse
    {
        $this->logger->info('Creating backup credentials');

        return $this->sourceJobRunner->runSyncAction(
            Config::PROJECT_BACKUP_COMPONENT,
            'generate-read-credentials',
            [
                'parameters' => [
                    'backupId' => $backupId,
                    'skipRegionValidation' => $this->config->shouldSkipRegionValidation(),
                ],
            ],
            $this->config->getAppBackupTag(),
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
                    'exportStructureOnly' => $this->config->directDataMigration() ||
                        $this->config->shouldMigrateStructureOnly(),
                    'skipRegionValidation' => $this->config->shouldSkipRegionValidation(),
                ],
            ],
            $this->config->getAppBackupTag(),
        );
        if ($job->status !== self::JOB_STATUS_SUCCESS) {
            /** @var array{message: string} $result */
            $result = $job->result;
            throw new UserException('Project snapshot create error: ' . $result['message']);
        }
        $this->logger->info('Source project snapshot created');
    }

    private function restoreDestinationProject(ActionResponse $restoreCredentials): void
    {
        $this->logger->info('Restoring current project from snapshot');

        $configData = $this->getRestoreConfigData($restoreCredentials);

        $job = $this->destJobRunner->runJob(
            Config::PROJECT_RESTORE_COMPONENT,
            $configData,
            $this->config->getAppRestoreTag(),
        );

        if ($job->status !== self::JOB_STATUS_SUCCESS) {
            /** @var array{message: string} $result */
            $result = $job->result;
            throw new UserException('Project restore error: ' . $result['message']);
        }
        $this->logger->info('Current project restored');
    }

    private function migrateSecrets(): void
    {
        $this->logger->info('Migrating configurations with secrets', ['secrets']);

        $sourceDevBranches = new DevBranches($this->sourceProjectStorageClient);
        $sourceBranches = $sourceDevBranches->listBranches();
        /** @var array{id: string, isDefault: bool}|false $defaultSourceBranch */
        $defaultSourceBranch = current(array_filter($sourceBranches, function ($b) {
            /** @var array{isDefault: bool} $b */
            return $b['isDefault'] === true;
        }));

        if ($defaultSourceBranch === false) {
            throw new UserException('No default branch found in source project');
        }

        $sourceComponentsApi = new Components($this->sourceProjectStorageClient);
        $components = $sourceComponentsApi->listComponents();
        if (!$components) {
            $this->logger->info('There are no components to migrate.', ['secrets']);
            return;
        }

        foreach ($components as $component) {
            /** @var array{id: string, configurations: array} $component */
            if (in_array($component['id'], self::OBSOLETE_COMPONENTS, true)) {
                $this->logger->info(
                    sprintf('Components "%s" is obsolete, skipping migration...', $component['id']),
                    ['secrets'],
                );
                continue;
            }

            /** @var array{id: string} $config */
            foreach ($component['configurations'] as $config) {
                if ($this->config->getConfigurationsToMigrate() &&
                    !in_array($config['id'], $this->config->getConfigurationsToMigrate(), true)) {
                    $this->logger->info(
                        sprintf(
                            'Skipping configuration "%s" of component "%s"',
                            $config['id'],
                            $component['id'],
                        ),
                        ['secrets'],
                    );
                    continue;
                }

                $this->logger->info(
                    sprintf(
                        '%sMigrating configuration "%s" of component "%s"',
                        $this->config->isDryRun() ? '[dry-run] ' : '',
                        $config['id'],
                        $component['id'],
                    ),
                    ['secrets'],
                );

                try {
                    $response = $this->migrationsClient
                        ->migrateConfiguration(
                            $this->config->getSourceProjectToken(),
                            Utils::getStackFromProjectUrl($this->destinationProjectUrl),
                            $this->destinationProjectToken,
                            $component['id'],
                            $config['id'],
                            $defaultSourceBranch['id'],
                            $this->config->isDryRun(),
                        );
                } catch (EncryptionClientException $e) {
                    $this->logger->error(
                        sprintf(
                            'Migrating configuration "%s" of component "%s" failed: %s',
                            $config['id'],
                            $component['id'],
                            $e->getMessage(),
                        ),
                        [
                            'exception' => $e,
                        ],
                    );
                    continue;
                }

                if (in_array($component['id'], self::SNOWFLAKE_WRITER_COMPONENT_IDS, true)) {
                    /** @var array{data: array{componentId: string, configId: string}} $response */
                    $this->preserveProperSnowflakeWorkspace(
                        $component['id'],
                        $config['id'],
                        $response['data']['componentId'],
                        $response['data']['configId'],
                    );
                }

                /** @var array{message: string, warnings?: array<string>} $response */
                $message = $response['message'];
                if ($this->config->isDryRun()) {
                    $message = '[dry-run] ' . $message;
                }

                $this->logger->info($message, ['secrets']);

                foreach ($response['warnings'] ?? [] as $warning) {
                    $this->logger->warning($warning, ['secrets']);
                }
            }
        }
    }

    private function migrateDataOfTablesDirectly(): void
    {
        $this->logger->info('Migrate data of tables directly.');

        $parameters = [
            'mode' => $this->config->getMigrateDataMode(),
            'sourceKbcUrl' => $this->config->getSourceProjectUrl(),
            '#sourceKbcToken' => $this->config->getSourceProjectToken(),
            'dryRun' => $this->config->isDryRun(),
            'isSourceByodb' => $this->config->isSourceByodb(),
            'sourceByodb' => $this->config->getSourceByodb(),
            'includeWorkspaceSchemas' => $this->config->getIncludeWorkspaceSchemas(),
            'preserveTimestamp' => $this->config->preserveTimestamp(),
            'tables' => $this->config->getTablesToMigrate(),
        ];

        if ($this->config->getMigrateDataMode() === 'database' && !empty($this->config->getDb())) {
            $parameters['db'] = $this->config->getDb();
        }

        $this->destJobRunner->runJob(
            Config::DATA_OF_TABLES_MIGRATE_COMPONENT,
            [
                'parameters' => $parameters,
            ],
            $this->config->getAppTablesDataTag(),
        );

        $this->logger->info('Data of tables has been migrated.');
    }

    private function migrateSnowflakeWriters(): void
    {
        $this->logger->info('Migrating Snowflake writers');
        $job = $this->destJobRunner->runJob(
            Config::SNOWFLAKE_WRITER_MIGRATE_COMPONENT,
            [
                'parameters' => [
                    'sourceKbcUrl' => $this->config->getSourceProjectUrl(),
                    '#sourceKbcToken' => $this->config->getSourceProjectToken(),
                    'dryRun' => $this->config->isDryRun(),
                ],
            ],
        );

        if ($job->status !== self::JOB_STATUS_SUCCESS) {
            /** @var array{message: string} $result */
            $result = $job->result;
            throw new UserException('Snowflake writers migration error: ' . $result['message']);
        }
        $this->logger->info('Snowflake writers migrated');
    }

    /**
     * @return array{
     *     parameters: array{
     *          s3?: array{
     *              backupUri: string,
     *              accessKeyId: string,
     *              "#secretAccessKey": string,
     *              "#sessionToken": string
     *          },
     *          abs?: array{
     *              container: string,
     *              "#connectionString": string
     *          },
     *          gcs?: array{
     *              projectId: string,
     *              bucket: string,
     *              backupUri: string,
     *              credentials: array{
     *                  "#accessToken": string,
     *                  expiresIn: int,
     *                  tokenType: string
     *              },
     *          },
     *          useDefaultBackend: bool,
     *          restoreConfigs: bool,
     *          restorePermanentFiles: bool,
     *          restoreTriggers: bool,
     *          restoreNotifications: bool,
     *          restoreBuckets: bool,
     *          restoreTables: bool,
     *          restoreProjectMetadata: bool,
     *          checkEmptyProject: bool
     *     }
     * }
     */
    private function getRestoreConfigData(ActionResponse $restoreCredentials): array
    {
        $json = json_encode($restoreCredentials->data);
        assert(is_string($json));
        /** @var array{backupUri: string, container?: string, projectId?: string, bucket?: string, credentials: array{accessKeyId?: string, secretAccessKey?: string, sessionToken?: string, connectionString?: string, accessToken?: string, expiresIn?: int, tokenType?: string}} $restoreData */
        $restoreData = json_decode($json, true);
        $backendConfig = $this->getBackendConfig($restoreData);
        $commonParameters = $this->getCommonRestoreParameters();

        /** @var array{
         *      s3?: array{
         *          backupUri: string,
         *          accessKeyId: string,
         *          "#secretAccessKey": string,
         *          "#sessionToken": string
         *      },
         *      abs?: array{
         *          container: string,
         *          "#connectionString": string
         *      },
         *      gcs?: array{
         *          projectId: string,
         *          bucket: string,
         *          backupUri: string,
         *          credentials: array{"#accessToken": string, expiresIn: int, tokenType: string}
         *      },
         *      useDefaultBackend: bool,
         *      restoreConfigs: bool,
         *      restorePermanentFiles: bool,
         *      restoreTriggers: bool,
         *      restoreNotifications: bool,
         *      restoreBuckets: bool,
         *      restoreTables: bool,
         *      restoreProjectMetadata: bool,
         *      checkEmptyProject: bool
         * } $mergedParameters
        **/
        $mergedParameters = array_merge($backendConfig, $commonParameters);

        return [
            'parameters' => $mergedParameters,
        ];
    }

    /**
     * @param array{
     *     backupUri?: string,
     *     container?: string,
     *     projectId?: string,
     *     bucket?: string,
     *     credentials: array{
     *         accessKeyId?: string,
     *         secretAccessKey?: string,
     *         sessionToken?: string,
     *         connectionString?: string,
     *         accessToken?: string,
     *         expiresIn?: int,
     *         tokenType?: string
     *     }
     * } $restoreCredentials
     */
    private function getBackendConfig(array $restoreCredentials): array
    {
        /** @var array{backupUri: string, container?: string, projectId?: string, bucket?: string, credentials: array{accessKeyId?: string, secretAccessKey?: string, sessionToken?: string, connectionString?: string, accessToken?: string, expiresIn?: int, tokenType?: string}} $restoreCredentials */
        if (isset($restoreCredentials['credentials']['secretAccessKey'])) {
            /** @var array{backupUri: string, credentials: array{accessKeyId: string, secretAccessKey: string, sessionToken: string}} $restoreCredentials */
            return [
                's3' => [
                    'backupUri' => $restoreCredentials['backupUri'],
                    'accessKeyId' => $restoreCredentials['credentials']['accessKeyId'],
                    '#secretAccessKey' => $restoreCredentials['credentials']['secretAccessKey'],
                    '#sessionToken' => $restoreCredentials['credentials']['sessionToken'],
                ],
            ];
        }

        if (isset($restoreCredentials['credentials']['connectionString'])) {
            /** @var array{
             * container: string,
             * credentials: array{connectionString: string}
             * } $restoreCredentials */
            return [
                'abs' => [
                    'container' => $restoreCredentials['container'],
                    '#connectionString' => $restoreCredentials['credentials']['connectionString'],
                ],
            ];
        }

        if (isset($restoreCredentials['credentials']['accessToken'])) {
            /** @var array{projectId: string, bucket: string, backupUri: string, credentials: array{accessToken: string, expiresIn: int, tokenType: string}} $restoreCredentials */
            return [
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
            ];
        }

        throw new UserException('Unrecognized restore credentials.');
    }

    private function getCommonRestoreParameters(): array
    {
        $restoreConfigurations = $this->config->shouldMigrateConfigurations();
        if ($this->config->shouldMigrateSecrets() === true) {
            // When migrating secrets, configurations will be migrated by the encryption-api,
            $restoreConfigurations = false;
        }
        return [
            'dryRun' => $this->config->isDryRun(),
            'useDefaultBackend' => true,
            'restoreConfigs' => $restoreConfigurations,
            'restorePermanentFiles' => $this->config->shouldMigratePermanentFiles(),
            'restoreTriggers' => $this->config->shouldMigrateTriggers(),
            'restoreNotifications' => $this->config->shouldMigrateNotifications(),
            'restoreBuckets' => $this->config->shouldMigrateBuckets(),
            'restoreTables' => $this->config->shouldMigrateTables(),
            'restoreProjectMetadata' => $this->config->shouldMigrateProjectMetadata(),
            'configurationsToMigrate' => $this->config->getConfigurationsToMigrate(),
            'tablesToMigrate' => $this->config->getTablesToMigrate(),
            'checkEmptyProject' => $this->config->checkEmptyProject(),
        ];
    }

    private function preserveProperSnowflakeWorkspace(
        string $sourceComponentId,
        string $sourceConfigurationId,
        string $destinationComponentId,
        string $destinationConfigurationId,
    ): void {
        if ($this->config->isDryRun()) {
            return;
        }
        $sourceComponentsApi = new Components($this->sourceProjectStorageClient);
        $sourceConfigurationData = (array) $sourceComponentsApi
            ->getConfiguration($sourceComponentId, $sourceConfigurationId);

        $destinationComponentsApi = new Components($this->destProjectStorageClient);
        $destinationConfigurationData = (array) $destinationComponentsApi
            ->getConfiguration($destinationComponentId, $destinationConfigurationId);

        /** @var array{configuration: array{parameters: array{db: array{user?: string}}}} $sourceConfigurationData */
        $snowflakeUser = $sourceConfigurationData['configuration']['parameters']['db']['user'] ?? null;
        if ($snowflakeUser === null) {
            $this->logger->info(
                sprintf(
                    "Configuration with ID '%s' (%s) does not have a Snowflake workspace.",
                    $sourceConfigurationId,
                    $sourceComponentId,
                ),
                ['secrets'],
            );
            return;
        }

        $migratedWorkspaceParameters = $this->migratedSnowflakeWorkspaces[$snowflakeUser] ?? null;

        if ($migratedWorkspaceParameters) {
            // Use the existing Snowflake workspace from a previous configuration that has the same source workspace
            /** @var array{configuration: array{parameters: array{db: array}}, name: string, description: string, isDisabled: bool} $destinationConfigurationData */
            $destinationConfigurationData['configuration']['parameters']['db'] = $migratedWorkspaceParameters;

            $destinationConfiguration = (new Configuration())
                ->setConfigurationId($destinationConfigurationId)
                ->setComponentId($destinationComponentId)
                ->setName($destinationConfigurationData['name'])
                ->setDescription($destinationConfigurationData['description'])
                ->setIsDisabled($destinationConfigurationData['isDisabled'])
                ->setConfiguration($destinationConfigurationData['configuration']);

            $destinationComponentsApi->updateConfiguration($destinationConfiguration);

            /** @var array{user: string} $migratedWorkspaceParameters */
            $this->logger->info(
                sprintf(
                    "Used existing Snowflake workspace '%s' for configuration with ID '%s' (%s).",
                    $migratedWorkspaceParameters['user'],
                    $destinationConfigurationId,
                    $destinationComponentId,
                ),
                ['secrets'],
            );
            return;
        }

        // Store Snowflake workspace for next configurations
        /** @var array{configuration: array{parameters: array{db: array}}} $destinationConfigurationData */
        $workspaceParameters = $destinationConfigurationData['configuration']['parameters']['db'];
        $this->migratedSnowflakeWorkspaces[$snowflakeUser] = $workspaceParameters;
    }
}
