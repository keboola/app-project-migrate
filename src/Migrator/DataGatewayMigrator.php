<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Migrator;

use Keboola\AppProjectMigrate\Config;
use Keboola\Component\UserException;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\WorkspaceLoginType;
use Psr\Log\LoggerInterface;

class DataGatewayMigrator
{
    /**
     * Cache of migrated workspaces by source schema.
     * When multiple configs share the same schema, they can reuse the same workspace.
     *
     * @var array<string, array{
     *     id: int,
     *     connection: array<string, mixed>
     * }>
     */
    private array $migratedWorkspaces = [];

    public function __construct(
        private readonly StorageClient $destStorageClient,
        private readonly LoggerInterface $logger,
        private readonly bool $dryRun = false,
    ) {
    }

    public function migrate(): void
    {
        $this->logger->info('Migrating Data Gateway configurations');

        $destComponentsApi = new Components($this->destStorageClient);
        $components = $destComponentsApi->listComponents();

        $dataGatewayComponent = null;
        foreach ($components as $component) {
            if ($component['id'] === Config::DATA_GATEWAY_COMPONENT) {
                $dataGatewayComponent = $component;
                break;
            }
        }

        if ($dataGatewayComponent === null || empty($dataGatewayComponent['configurations'])) {
            $this->logger->info('No Data Gateway configurations found.');
            return;
        }

        /** @var array<int, array{id: string}> $configurations */
        $configurations = $dataGatewayComponent['configurations'];
        foreach ($configurations as $config) {
            $this->migrateConfiguration($config['id'], $destComponentsApi);
        }

        $this->logger->info('Data Gateway configurations migrated');
    }

    private function migrateConfiguration(
        string $configId,
        Components $destComponentsApi,
    ): void {
        if ($this->dryRun) {
            $this->logger->info(sprintf('[dry-run] Would migrate Data Gateway config "%s"', $configId));
            return;
        }

        $this->logger->info(sprintf('Migrating Data Gateway config "%s"', $configId));

        /** @var array{id: string, name: string, configuration: array{parameters?: array{db?: array{schema?: string}}}} $configData */
        $configData = $destComponentsApi->getConfiguration(
            Config::DATA_GATEWAY_COMPONENT,
            $configId,
        );

        $sourceSchema = $configData['configuration']['parameters']['db']['schema'] ?? null;

        // Check if we already migrated a workspace for this schema
        if ($sourceSchema !== null && isset($this->migratedWorkspaces[$sourceSchema])) {
            $this->logger->info(sprintf(
                'Reusing already migrated workspace %d for config "%s"',
                $this->migratedWorkspaces[$sourceSchema]['id'],
                $configId,
            ));
            $this->updateConfiguration($configData, $this->migratedWorkspaces[$sourceSchema], $destComponentsApi);
            return;
        }

        $publicKey = $this->generatePublicKey();

        /** @var array{id: int, connection: array<string, mixed>} $newWorkspace */
        $newWorkspace = $destComponentsApi->createConfigurationWorkspace(
            Config::DATA_GATEWAY_COMPONENT,
            $configId,
            [
                'publicKey' => $publicKey,
                'backend' => 'snowflake',
                'loginType' => WorkspaceLoginType::SNOWFLAKE_PERSON_KEYPAIR,
            ],
        );

        // Cache the workspace for potential reuse by other configs with same schema
        if ($sourceSchema !== null) {
            $this->migratedWorkspaces[$sourceSchema] = $newWorkspace;
        }

        $this->updateConfiguration($configData, $newWorkspace, $destComponentsApi);

        $this->logger->info(sprintf(
            'Data Gateway config "%s" migrated to workspace %d',
            $configId,
            $newWorkspace['id'],
        ));

        $this->logger->warning(sprintf(
            'Data Gateway workspace data NOT migrated for config "%s". User must load data manually.',
            $configId,
        ));
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     configuration: array{parameters?: array{db?: array<string, mixed>}}
     * } $configData
     * @param array{id: int, connection: array<string, mixed>} $workspace
     */
    private function updateConfiguration(
        array $configData,
        array $workspace,
        Components $destComponentsApi,
    ): void {
        $oldDb = $this->removeEncryptedKeys($configData['configuration']['parameters']['db'] ?? []);
        $configData['configuration']['parameters']['db'] = array_merge(
            $oldDb,
            $workspace['connection'],
            ['workspaceId' => $workspace['id']],
        );

        $destComponentsApi->updateConfiguration(
            (new Configuration())
                ->setComponentId(Config::DATA_GATEWAY_COMPONENT)
                ->setConfigurationId($configData['id'])
                ->setName($configData['name'])
                ->setConfiguration($configData['configuration']),
        );
    }

    /**
     * Remove all encrypted keys (starting with #) from the array to avoid mixing
     * old encrypted values with new plain values.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function removeEncryptedKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (!str_starts_with($key, '#')) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function generatePublicKey(): string
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $keyPair = openssl_pkey_new($config);
        if ($keyPair === false) {
            throw new UserException('Failed to generate RSA key pair');
        }

        $details = openssl_pkey_get_details($keyPair);
        if ($details === false || !isset($details['key']) || !is_string($details['key'])) {
            throw new UserException('Failed to get key details');
        }

        return $details['key'];
    }
}
