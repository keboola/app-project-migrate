<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\JobRunner;

use Keboola\JobQueueClient\Client;
use Keboola\JobQueueClient\DTO\Job;
use Keboola\JobQueueClient\JobData;
use Keboola\SyncActionsClient\ActionData;
use Keboola\SyncActionsClient\Client as SyncActionsClient;
use Keboola\SyncActionsClient\Model\ActionResponse;

class QueueV2JobRunner extends JobRunner
{
    private const MAX_DELAY = 10;

    public function runJob(string $componentId, array $data, ?string $tag = null): Job
    {
        $jobData = new JobData(
            $componentId,
            null,
            $data,
            'run',
            [],
            $tag,
        );
        $response = $this->getQueueClient()->createJob($jobData);

        $attempt = 0;
        $finished = false;
        while (!$finished) {
            $job = $this->getQueueClient()->getJob($response->id);
            $finished = $job->isFinished;
            $attempt++;
            sleep((int) min(pow(2, $attempt), self::MAX_DELAY));
        }

        return $job;
    }

    public function runSyncAction(
        string $componentId,
        string $action,
        array $data,
        ?string $tag = null,
    ): ActionResponse {
        $client = $this->getSyncActionsClient();

        $data = new ActionData($componentId, $action, $data, $tag);

        return $client->callAction($data);
    }

    private function getQueueClient(): Client
    {
        return new Client(
            $this->getServiceUrl('queue'),
            $this->storageApiClient->getTokenString(),
            [
                'logger' => $this->logger,
            ],
        );
    }

    private function getSyncActionsClient(): SyncActionsClient
    {
        return new SyncActionsClient(
            $this->getServiceUrl('sync-actions'),
            $this->storageApiClient->getTokenString(),
        );
    }
}
