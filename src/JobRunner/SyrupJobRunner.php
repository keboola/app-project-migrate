<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\JobRunner;

use Keboola\Syrup\Client;

class SyrupJobRunner extends JobRunner
{
    public function runJob(string $componentId, array $data, ?string $tag = null): array
    {
        $options = [
            'configData' => $data,
        ];
        if ($tag) {
            $options['tag'] = $tag;
        }
        return $this->getSyrupClient()->runJob(
            $componentId,
            $options,
        );
    }

    public function runSyncAction(string $componentId, string $action, array $data): array
    {
        return $this->getSyrupClient(1)->runSyncAction(
            $this->getServiceUrl('docker-runner'),
            $componentId,
            $action,
            $data
        );
    }

    private function getSyrupClient(?int $backoffMaxTries = null): Client
    {
        $config = [
            'token' => $this->storageApiClient->getTokenString(),
            'url' => $this->getServiceUrl('syrup'),
            'super' => 'docker',
            'runId' => $this->storageApiClient->getRunId(),
        ];

        if ($backoffMaxTries) {
            $config['backoffMaxTries'] = $backoffMaxTries;
        }

        return new Client($config);
    }
}
