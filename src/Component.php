<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\AppProjectMigrate\Checker\AfterMigration;
use Keboola\AppProjectMigrate\JobRunner\JobRunnerFactory;
use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
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
            $destinationTokenInfo = $sourceProjectClient->verifyToken();
        } catch (StorageClientException $e) {
            throw new UserException('Cannot authorize destination project: ' . $e->getMessage(), $e->getCode(), $e);
        }

        if (!$destinationTokenInfo['isMasterToken']) {
            throw new UserException(
                sprintf(
                    'The token "%s" in the destination project "%s" hasn\'t master permissions.',
                    $destinationTokenInfo['description'],
                    $destinationTokenInfo['owner']['name']
                )
            );
        }

        Utils::checkMigrationApps($sourceProjectClient, $destProjectClient);

        if (!Utils::checkIfProjectEmpty($destProjectClient, new Components($destProjectClient))) {
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
            JobRunnerFactory::create($sourceProjectClient, $logger),
            JobRunnerFactory::create($destProjectClient, $logger),
            $config->getSourceProjectUrl(),
            $config->getSourceProjectToken(),
            $config->directDataMigration(),
            $config->exportStructureOnly(),
            $logger
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
