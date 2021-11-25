<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\JobRunner;

use Keboola\JobQueueClient\Client;
use Keboola\JobQueueClient\JobData;
use Keboola\Syrup\Client as SyrupClient;

class QueueV2JobRunner extends JobRunner
{
    public function runJob(string $componentId, array $data): array
    {
        $jobData = new JobData($componentId, null, $data);
        $response = $this->getQueueClient()->createJob($jobData);

        $finished = false;
        while (!$finished) {
            $job = $this->getQueueClient()->getJob($response['id']);
            $finished = $job['isFinished'];
            sleep(10);
        }

        return $job;
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

    private function getQueueClient(): Client
    {
        return new Client(
            $this->logger,
            $this->getServiceUrl('queue'),
            $this->storageApiClient->getTokenString()
        );
    }

    private function getSyrupClient(?int $backoffMaxTries = null): SyrupClient
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

        return new SyrupClient($config);
    }
}
