<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Checker;

use Keboola\Component\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Psr\Log\LoggerInterface;

class AfterMigration
{
    private Client $sourceProjectClient;

    private Client $destProjectClient;

    private LoggerInterface $logger;

    public function __construct(Client $sourceProjectClient, Client $destProjectClient, LoggerInterface $logger)
    {
        $this->sourceProjectClient = $sourceProjectClient;
        $this->destProjectClient = $destProjectClient;
        $this->logger = $logger;
    }

    public function check(): void
    {
        $this->checkTables();
    }

    protected function checkTables(): void
    {
        $buckets = $this->destProjectClient->listBuckets();

        $isInvalid = false;
        foreach ($buckets as $bucket) {
            $tables = $this->destProjectClient->listTables($bucket['id']);
            foreach ($tables as $table) {
                try {
                    $sourceTable = $this->sourceProjectClient->getTable($table['id']);
                } catch (ClientException $e) {
                    $isInvalid = true;
                    $this->logger->warning($e->getMessage());
                    continue;
                }
                if ($sourceTable['rowsCount'] !== $table['rowsCount']) {
                    $isInvalid = true;
                    $this->logger->warning(sprintf(
                        'Bad row count: Bucket "%s", Table "%s". ' .
                        'Source table rows: "%d"; Destination table rows: "%s".',
                        $bucket['name'],
                        $table['name'],
                        $sourceTable['rowsCount'],
                        $table['rowsCount']
                    ));
                }
            }
        }

        if ($isInvalid) {
            throw new UserException('Failed post migration check.');
        }
    }
}
