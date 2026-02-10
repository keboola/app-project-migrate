<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\JobRunner;

use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;
use RuntimeException;

class JobRunnerFactory
{
    public static function create(Client $client, LoggerInterface $logger): JobRunner
    {
        /** @var array{owner: array{features: string[]}} $verifyToken */
        $verifyToken = $client->verifyToken();

        if (in_array('queuev2', $verifyToken['owner']['features'])) {
            return new QueueV2JobRunner($client, $logger);
        }

        throw new RuntimeException('No suitable JobRunner found for the provided Storage API token.');
    }
}
