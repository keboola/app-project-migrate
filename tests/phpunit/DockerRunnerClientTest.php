<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests;

use Keboola\AppProjectMigrate\DockerRunnerClient;
use Keboola\Syrup\Client;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Keboola\Syrup\Client as SyrupClient;

class DockerRunnerClientTest extends TestCase
{

    public function testRunJob(): void
    {
        $syrupClientMock = $this->createMock(SyrupClient::class);

        $syrupClientMock->expects($this->once())
            ->method('runJob')
            ->with(
                'migrate',
                ['config' => '123']
            )
            ->willReturn([
                'id' => '222',
                'status' => 'success',
            ]);

        $client = new DockerRunnerClient($syrupClientMock, 'https://sync-action.keboola.com');
        $job = $client->runJob('migrate', ['config' => '123']);

        $this->assertEquals(
            [
                'id' => '222',
                'status' => 'success',
            ],
            $job
        );
    }

    public function testRunSyncAction(): void
    {
        $syrupClientMock = $this->createMock(SyrupClient::class);

        $syrupClientMock->expects($this->once())
            ->method('runSyncAction')
            ->with(
                'https://sync-action.keboola.com',
                'migrate',
                'get-credentials',
                ['config' => '123']
            )
            ->willReturn(['response' => '1']);

        $client = new DockerRunnerClient($syrupClientMock, 'https://sync-action.keboola.com');
        $job = $client->runSyncAction(
            'migrate',
            'get-credentials',
            [
                'config' => '123',
            ]
        );

        $this->assertEquals(
            [
                'response' => '1',
            ],
            $job
        );
    }
}
