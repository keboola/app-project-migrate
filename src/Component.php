<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException as StorageClientException;

class Component extends BaseComponent
{
    public function run(): void
    {
        /** @var Config $config */
        $config = $this->getConfig();

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

        $migrate = new Migrate(
            Utils::createDockerRunnerClientFromStorageClient($sourceProjectClient),
            Utils::createDockerRunnerClientFromStorageClient($destProjectClient)
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
