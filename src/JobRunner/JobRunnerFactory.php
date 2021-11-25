<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\JobRunner;

use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;

class JobRunnerFactory
{
    public static function create(Client $client, LoggerInterface $logger): JobRunner
    {
        $verifyToken = $client->verifyToken();

        if (in_array('queuev2', $verifyToken['owner']['features'])) {
            return new QueueV2JobRunner($client, $logger);
        }

        return new SyrupJobRunner($client, $logger);
    }
}
