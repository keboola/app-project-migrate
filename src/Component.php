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

        $sourceProjectClient = $this->createStorageClient([
            'url' => $config->getSourceProjectUrl(),
            'token' => $config->getSourceProjectToken(),
        ]);
        try {
            $sourceTokenInfo = $sourceProjectClient->verifyToken();
        } catch (StorageClientException $e) {
            throw new UserException('Cannot authorize source project: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $destProjectClient = $this->createStorageClient([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);

        try {
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
                    $destinationTokenInfo['owner']['name']
                )
            );
        }

        $logger->info(sprintf(
            'Restoring current project from project %s (%d) at %s',
            $sourceTokenInfo['owner']['name'],
            $sourceTokenInfo['owner']['id'],
            $config->getSourceProjectUrl()
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

        $checkerAfterMigration = new AfterMigration($sourceProjectClient, $destProjectClient, $logger);
        $checkerAfterMigration->check();
    }

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
