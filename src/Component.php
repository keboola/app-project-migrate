<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException as StorageClientException;
use Keboola\StorageApi\Components;

class Component extends BaseComponent
{
    public function run(): void
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

        if (!Utils::checkIfProjectEmpty($destProjectClient, new Components($destProjectClient))) {
            $destinationTokenInfo = $sourceProjectClient->verifyToken();
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

        $migrate = new Migrate(
            Utils::createDockerRunnerClientFromStorageClient($sourceProjectClient),
            Utils::createDockerRunnerClientFromStorageClient($destProjectClient),
            $config->getSourceProjectUrl(),
            $config->getSourceProjectToken(),
            $logger
        );
        $migrate->run();
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
