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
            throw new \Exception('syrup service not found');
        }
        return $foundServices[0]['url'];
    }

    public static function createDockerRunnerClientFromStorageClient(StorageClient $sapiClient): SyrupClient
    {
        $baseUrl = self::getKeboolaServiceUrl(
            $sapiClient->indexAction()['services'],
            self::DOCKER_RUNNER_SERVICE_ID
        );
        return new SyrupClient([
            'url' => $baseUrl,
            'token' => $sapiClient->getTokenString(),
            'super' => 'docker',
            'runId' => $sapiClient->getRunId(),
        ]);
    }
}
