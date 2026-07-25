<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\AppProjectMigrate\Checker\AfterMigration;
use Keboola\AppProjectMigrate\JobRunner\JobRunnerFactory;
use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\EncryptionApiClient\Migrations;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException as StorageClientException;
use Keboola\StorageApi\Components;

class Component extends BaseComponent
{
    protected function run(): void
    {
        /** @var Config $config */
        $config = $this->getConfig();
        $logger = $this->getLogger();

        /** @var array{url: string, token: string} $sourceParams */
        $sourceParams = [
            'url' => $config->getSourceProjectUrl(),
            'token' => $config->getSourceProjectToken(),
        ];
        $sourceProjectClient = $this->createStorageClient($sourceParams);
        try {
            /** @var array{owner: array{name: string, id: int, features: string[]}} $sourceTokenInfo */
            $sourceTokenInfo = $sourceProjectClient->verifyToken();
        } catch (StorageClientException $e) {
            throw new UserException('Cannot authorize source project: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $kbcUrl = getenv('KBC_URL');
        $kbcToken = getenv('KBC_TOKEN');
        if ($kbcUrl === false || $kbcToken === false) {
            throw new UserException('KBC_URL and KBC_TOKEN environment variables must be set.');
        }
        /** @var array{url: string, token: string} $destParams */
        $destParams = [
            'url' => $kbcUrl,
            'token' => $kbcToken,
        ];
        $destProjectClient = $this->createStorageClient($destParams);

        try {
            /** @var array{owner: array{name: string, id: int, features: string[]}} $destinationTokenInfo */
            $destinationTokenInfo = $destProjectClient->verifyToken();
        } catch (StorageClientException $e) {
            throw new UserException('Cannot authorize destination project: ' . $e->getMessage(), $e->getCode(), $e);
        }

        if ($config->shouldMigrateSecrets() && !$config->getSourceManageToken()) {
            throw new UserException('#sourceManageToken must be set.', 422);
        }

        Utils::checkMigrationApps($sourceProjectClient, $destProjectClient);

        if ($config->checkEmptyProject() &&
            !Utils::checkIfProjectEmpty($destProjectClient, new Components($destProjectClient))
        ) {
            throw new UserException(
                sprintf(
                    'Destination project "%s" is not empty.',
                    $destinationTokenInfo['owner']['name'],
                ),
            );
        }

        $logger->info(sprintf(
            'Restoring current project from project %s (%d) at %s',
            $sourceTokenInfo['owner']['name'],
            $sourceTokenInfo['owner']['id'],
            $config->getSourceProjectUrl(),
        ));

        $sourceJobRunner = JobRunnerFactory::create($sourceProjectClient, $logger);
        $destJobRunner = JobRunnerFactory::create($destProjectClient, $logger);
        $migrationsClient = new Migrations($config->getSourceManageToken() ?? '', [
            'url' => $sourceProjectClient->getServiceUrl('encryption'),
        ]);

        $migrate = new Migrate(
            $config,
            $sourceJobRunner,
            $destJobRunner,
            $sourceProjectClient,
            $destProjectClient,
            $migrationsClient,
            $destProjectClient->getApiUrl(),
            $destProjectClient->getTokenString(),
            $logger,
        );
        $migrate->run();

        $checkerAfterMigration = new AfterMigration(
            $sourceProjectClient,
            $destProjectClient,
            $logger,
            $config->shouldMigrateStructureOnly(),
        );
        $checkerAfterMigration->check();
    }

    /**
     * @param array{url: string, token: string} $params
     */
    private function createStorageClient(array $params): StorageClient
    {
        $client = new StorageClient($params);
        $client->setRunId($this->getKbcRunId());
        return $client;
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }

    private function getKbcRunId(): string
    {
        return (string) getenv('KBC_RUNID');
    }
}
