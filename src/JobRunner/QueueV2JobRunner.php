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
    public function runJob(string $componentId, array $data, ?string $tag = null): Job
    {
        $jobData = new JobData($componentId, null, $data, 'run', [], $tag);

        $client = new Client(
            $this->getServiceUrl('queue'),
            $this->storageApiClient->getTokenString(),
            [
                'logger' => $this->logger,
            ],
        );
        $response = $client->createJob($jobData);

        return $client->waitForJobCompletion($response->id);
    }

    public function runSyncAction(
        string $componentId,
        string $action,
        array $data,
        ?string $tag = null,
    ): ActionResponse {
        $data = new ActionData($componentId, $action, $data, $tag);

        $client = new SyncActionsClient(
            $this->getServiceUrl('sync-actions'),
            $this->storageApiClient->getTokenString(),
        );
        return $client->callAction($data);
    }
}
