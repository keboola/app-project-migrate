<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Migrator;

use Keboola\AppProjectMigrate\Config;
use Keboola\Component\UserException;
use Keboola\EncryptionApiClient\Encryption;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\WorkspaceLoginType;
use Keboola\StorageApi\Workspaces;
use Psr\Log\LoggerInterface;

class DataGatewayMigrator
{
    /**
     * @var array<int, array{
     *     workspaceId: int,
     *     host: string,
     *     user: string,
     *     schema: string,
     *     warehouse: string,
     *     database: string,
     *     role: string|null,
     *     encryptedPrivateKey: string
     * }>
     */
    private array $migratedWorkspaces = [];

    private Encryption $encryptionClient;
    private string $projectId;

    public function __construct(
        private readonly StorageClient $destStorageClient,
        private readonly LoggerInterface $logger,
        private readonly bool $dryRun = false,
        ?Encryption $encryptionClient = null,
    ) {
        /** @var array{owner: array{id: int}} $tokenInfo */
        $tokenInfo = $this->destStorageClient->verifyToken();
        $this->projectId = (string) $tokenInfo['owner']['id'];

        $this->encryptionClient = $encryptionClient ?? new Encryption(
            $this->destStorageClient->getTokenString(),
            ['url' => $this->destStorageClient->getServiceUrl('encryption')],
        );
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

        $destWorkspaces = new Workspaces($this->destStorageClient);

        /** @var array<int, array{id: string}> $configurations */
        $configurations = $dataGatewayComponent['configurations'];
        foreach ($configurations as $config) {
            $this->migrateConfiguration($config['id'], $destComponentsApi, $destWorkspaces);
        }

        $this->logger->info('Data Gateway configurations migrated');
    }

    private function migrateConfiguration(
        string $configId,
        Components $destComponentsApi,
        Workspaces $destWorkspaces,
    ): void {
        if ($this->dryRun) {
            $this->logger->info(sprintf('[dry-run] Would migrate Data Gateway config "%s"', $configId));
            return;
        }

        $this->logger->info(sprintf('Migrating Data Gateway config "%s"', $configId));

        /** @var array{id: string, name: string, configuration: array{parameters?: array{db?: array{workspaceId?: int}}}} $configData */
        $configData = $destComponentsApi->getConfiguration(
            Config::DATA_GATEWAY_COMPONENT,
            $configId,
        );

        $sourceWorkspaceId = $configData['configuration']['parameters']['db']['workspaceId'] ?? null;

        if ($sourceWorkspaceId !== null && isset($this->migratedWorkspaces[$sourceWorkspaceId])) {
            $this->logger->info(sprintf(
                'Reusing already migrated workspace %d for config "%s"',
                $this->migratedWorkspaces[$sourceWorkspaceId]['workspaceId'],
                $configId,
            ));
            $this->updateConfigurationFromCache($configData, $sourceWorkspaceId, $destComponentsApi);
            return;
        }

        $keyPair = $this->generateKeyPair();

        /** @var array{id: int, connection: array{host: string, user: string, schema: string, warehouse: string, database: string, role?: string|null}} $newWorkspace */
        $newWorkspace = $destWorkspaces->createWorkspace([
            'readOnlyStorageAccess' => true,
            'backend' => 'snowflake',
            'loginType' => WorkspaceLoginType::SNOWFLAKE_SERVICE_KEYPAIR,
            'publicKey' => $keyPair['publicKey'],
        ]);

        $encryptedPrivateKey = $this->encryptionClient->encryptPlainTextForConfiguration(
            $keyPair['privateKey'],
            $this->projectId,
            Config::DATA_GATEWAY_COMPONENT,
            $configId,
        );

        if ($sourceWorkspaceId !== null) {
            $this->migratedWorkspaces[$sourceWorkspaceId] = [
                'workspaceId' => $newWorkspace['id'],
                'host' => $newWorkspace['connection']['host'],
                'user' => $newWorkspace['connection']['user'],
                'schema' => $newWorkspace['connection']['schema'],
                'warehouse' => $newWorkspace['connection']['warehouse'],
                'database' => $newWorkspace['connection']['database'],
                'role' => $newWorkspace['connection']['role'] ?? null,
                'encryptedPrivateKey' => $encryptedPrivateKey,
            ];
        }

        $this->updateConfiguration($configData, $newWorkspace, $encryptedPrivateKey, $destComponentsApi);

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
     */
    private function updateConfigurationFromCache(
        array $configData,
        int $sourceWorkspaceId,
        Components $destComponentsApi,
    ): void {
        $cached = $this->migratedWorkspaces[$sourceWorkspaceId];

        $oldDb = $this->removeEncryptedKeys($configData['configuration']['parameters']['db'] ?? []);
        $configData['configuration']['parameters']['db'] = array_merge($oldDb, [
            'host' => $cached['host'],
            'user' => $cached['user'],
            'schema' => $cached['schema'],
            'warehouse' => $cached['warehouse'],
            'database' => $cached['database'],
            'workspaceId' => $cached['workspaceId'],
            'role' => $cached['role'],
            'loginType' => 'snowflake-service-keypair',
            '#privateKey' => $cached['encryptedPrivateKey'],
        ]);

        $destComponentsApi->updateConfiguration(
            (new Configuration())
                ->setComponentId(Config::DATA_GATEWAY_COMPONENT)
                ->setConfigurationId($configData['id'])
                ->setName($configData['name'])
                ->setConfiguration($configData['configuration']),
        );
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     configuration: array{parameters?: array{db?: array<string, mixed>}}
     * } $configData
     * @param array{
     *     id: int,
     *     connection: array{
     *         host: string,
     *         user: string,
     *         schema: string,
     *         warehouse: string,
     *         database: string,
     *         role?: string|null
     *     }
     * } $newWorkspace
     */
    private function updateConfiguration(
        array $configData,
        array $newWorkspace,
        string $encryptedPrivateKey,
        Components $destComponentsApi,
    ): void {
        $oldDb = $this->removeEncryptedKeys($configData['configuration']['parameters']['db'] ?? []);
        $configData['configuration']['parameters']['db'] = array_merge($oldDb, [
            'host' => $newWorkspace['connection']['host'],
            'user' => $newWorkspace['connection']['user'],
            'schema' => $newWorkspace['connection']['schema'],
            'warehouse' => $newWorkspace['connection']['warehouse'],
            'database' => $newWorkspace['connection']['database'],
            'workspaceId' => $newWorkspace['id'],
            'role' => $newWorkspace['connection']['role'] ?? null,
            'loginType' => 'snowflake-service-keypair',
            '#privateKey' => $encryptedPrivateKey,
        ]);

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

    /**
     * @return array{privateKey: string, publicKey: string}
     */
    private function generateKeyPair(): array
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $keyPair = openssl_pkey_new($config);
        if ($keyPair === false) {
            throw new UserException('Failed to generate RSA key pair');
        }

        $privateKeyPem = null;
        openssl_pkey_export($keyPair, $privateKeyPem);
        if (!is_string($privateKeyPem)) {
            throw new UserException('Failed to export private key');
        }

        $details = openssl_pkey_get_details($keyPair);
        if ($details === false || !isset($details['key']) || !is_string($details['key'])) {
            throw new UserException('Failed to get key pair details');
        }

        return [
            'privateKey' => $privateKeyPem,
            'publicKey' => $details['key'],
        ];
    }
}
