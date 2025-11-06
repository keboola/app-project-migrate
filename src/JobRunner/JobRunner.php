<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\JobRunner;

use Exception;
use Keboola\JobQueueClient\DTO\Job;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\IndexOptions;
use Keboola\SyncActionsClient\Model\ActionResponse;
use Psr\Log\LoggerInterface;

abstract class JobRunner
{
    protected Client $storageApiClient;

    protected LoggerInterface $logger;

    /** @var array<int, array{id: string, url: string}>|null $services */
    private ?array $services = null;

    public function __construct(Client $client, LoggerInterface $logger)
    {
        $this->storageApiClient = $client;
        $this->logger = $logger;
    }

    abstract public function runJob(string $componentId, array $data, ?string $tag = null): Job;

    abstract public function runSyncAction(
        string $componentId,
        string $action,
        array $data,
        ?string $tag = null,
    ): ActionResponse;

    public function getServiceUrl(string $serviceId): string
    {
        /** @var array<int, array{id: string, url: string}> $foundServices */
        $foundServices = array_values(array_filter($this->getServices(), function (array $service) use ($serviceId) {
            return $service['id'] === $serviceId;
        }));
        if (empty($foundServices)) {
            throw new Exception(sprintf('%s service not found', $serviceId));
        }
        return $foundServices[0]['url'];
    }

    /**
     * @return array<int, array{id: string, url: string}>
     */
    private function getServices(): array
    {
        $options = new IndexOptions();
        $options->setExclude(['components']);

        if (!$this->services) {
            /** @var array{services: array<int, array{id: string, url: string}>} $indexResult */
            $indexResult = $this->storageApiClient->indexAction($options);
            $this->services = $indexResult['services'];
        }
        return $this->services;
    }
}
