<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\StorageApi\Client as StorageClient;
use Keboola\Syrup\Client as SyrupClient;

class Utils
{
    private const DOCKER_RUNNER_SERVICE_ID = 'docker-runner';

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
            self::DOCKER_RUNNER_SERVICE_ID
        );
        $syrupClient = new SyrupClient([
            'url' => $baseUrl,
            'token' => $sapiClient->getTokenString(),
            'super' => 'docker',
            'runId' => $sapiClient->getRunId(),
        ]);
        return new DockerRunnerClient($syrupClient, $baseUrl);
    }
}
