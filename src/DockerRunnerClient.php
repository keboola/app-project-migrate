<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Syrup\Client as SyrupClient;

class DockerRunnerClient
{
    /** @var SyrupClient  */
    private $syrupClient;

    /** @var string  */
    private $syncActionBaseUrl;

    public function __construct(SyrupClient $syrupClient, string $syncActionBaseUrl)
    {
        $this->syrupClient = $syrupClient;
        $this->syncActionBaseUrl = $syncActionBaseUrl;
    }

    public function runJob(string $component, array $options = []): array
    {
        return $this->syrupClient->runJob($component, $options);
    }

    public function runSyncAction(string $component, string $action, array $configData): array
    {
        return $this->syrupClient->runSyncAction(
            $this->syncActionBaseUrl,
            $component,
            $action,
            $configData
        );
    }
}
