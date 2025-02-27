<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Component\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Client as SyrupClient;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Validation;

class Utils
{
    private const IGNORED_COMPONENTS = ['keboola.app-project-migrate-large-tables', 'keboola.app-project-migrate'];

    public static function checkIfProjectEmpty(Client $client, Components $componentsClient): bool
    {
        $listOfComponents = $componentsClient->listComponents();
        if (!empty($listOfComponents)) {
            foreach ($listOfComponents as $component) {
                if (!in_array($component['id'], self::IGNORED_COMPONENTS)) {
                    return false;
                }
            }
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
        $validator = Validation::createValidator();
        $violations = $validator->validate($url, new Url());
        if (count($violations) > 0) {
            throw new UserException(sprintf('Invalid destination project URL: "%s".', $url));
        }

        $parsedUrl = parse_url($url);
        return is_array($parsedUrl) && isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
    }
}
