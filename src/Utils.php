<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Component\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Client as SyrupClient;

class Utils
{
    private const DOCKER_RUNNER_SERVICE_ID = 'docker-runner';

    private const SYRUP_SERVICE_ID = 'syrup';

    public static function getKeboolaServiceUrl(array $services, string $serviceId): string
    {
        $foundServices = array_values(array_filter($services, function ($service) use ($serviceId) {
            return $service['id'] === $serviceId;
        }));
        if (empty($foundServices)) {
            throw new \Exception(sprintf('%s service not found', $serviceId));
        }
        return $foundServices[0]['url'];
    }

    public static function createDockerRunnerClientFromStorageClient(StorageClient $sapiClient): DockerRunnerClient
    {
        $services =  $sapiClient->indexAction()['services'];
        $baseUrl = self::getKeboolaServiceUrl(
            $services,
            self::SYRUP_SERVICE_ID
        );
        $syncUrl = self::getKeboolaServiceUrl(
            $services,
            self::DOCKER_RUNNER_SERVICE_ID
        );
        $syrupClient = new SyrupClient([
            'url' => $baseUrl,
            'token' => $sapiClient->getTokenString(),
            'super' => 'docker',
            'runId' => $sapiClient->getRunId(),
        ]);
        return new DockerRunnerClient($syrupClient, $syncUrl);
    }

    public static function checkIfProjectEmpty(Client $client, Components $componentsClient): bool
    {
        $listOfComponents = $componentsClient->listComponents();
        if (!empty($listOfComponents)) {
            return false;
        }

        $listOfBuckets = $client->listBuckets();
        if (!empty($listOfBuckets)) {
            return false;
        }

        return true;
    }

    public static function checkMigrationApps(Client $sourceProjectClient, Client $destinationProjectClient): void
    {
        // Check app in the source project
        $sourceApplications = $sourceProjectClient->apiGet('');
        $listSourceApplication = array_map(fn(array $v) => $v['id'], $sourceApplications['components']);

        $requiredSourceApp = [Config::PROJECT_BACKUP_COMPONENT];

        $missingSourceApp = array_diff($requiredSourceApp, $listSourceApplication);
        if ($missingSourceApp) {
            throw new UserException(sprintf(
                'Missing "%s" application in the source project.',
                implode(', ', $missingSourceApp)
            ));
        }

        // Check app in the destination project
        $destinationApplications = $destinationProjectClient->apiGet('');
        $listDestinationApplication = array_map(fn(array $v) => $v['id'], $destinationApplications['components']);

        $requiredDestinationApp = [
            Config::PROJECT_RESTORE_COMPONENT,
            Config::ORCHESTRATOR_MIGRATE_COMPONENT,
            Config::SNOWFLAKE_WRITER_MIGRATE_COMPONENT,
        ];

        $missingDestinationApp = array_diff($requiredDestinationApp, $listDestinationApplication);
        if ($missingDestinationApp) {
            throw new UserException(sprintf(
                'Missing "%s" application in the destination project.',
                implode(', ', $missingDestinationApp)
            ));
        }
    }
}
