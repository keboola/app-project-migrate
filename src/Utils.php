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

    public static function getStackFromProjectUrl(string $url): string
    {
        if (!preg_match('~^https?://~', $url)) {
            $url = 'https://' . $url;
        }
        return parse_url($url)['host'] ?? '';
    }
}
