<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\JobRunner;

use Exception;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\IndexOptions;
use Psr\Log\LoggerInterface;

abstract class JobRunner
{
    protected Client $storageApiClient;

    protected LoggerInterface $logger;

    private ?array $services = null;

    public function __construct(Client $client, LoggerInterface $logger)
    {
        $this->storageApiClient = $client;
        $this->logger = $logger;
    }

    abstract public function runJob(string $componentId, array $data, ?string $tag = null): array;

    abstract public function runSyncAction(string $componentId, string $action, array $data): array;

    public function getServiceUrl(string $serviceId): string
    {
        $foundServices = array_values(array_filter($this->getServices(), function ($service) use ($serviceId) {
            return $service['id'] === $serviceId;
        }));
        if (empty($foundServices)) {
            throw new Exception(sprintf('%s service not found', $serviceId));
        }
        return $foundServices[0]['url'];
    }

    private function getServices(): array
    {
        $options = new IndexOptions();
        $options->setExclude(['components']);

        if (!$this->services) {
            $this->services = $this->storageApiClient->indexAction($options)['services'];
        }
        return $this->services;
    }
}
