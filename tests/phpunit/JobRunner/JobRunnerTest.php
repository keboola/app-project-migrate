<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests\JobRunner;

use Generator;
use Keboola\AppProjectMigrate\JobRunner\QueueV2JobRunner;
use Keboola\StorageApi\Client as StorageClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

class JobRunnerTest extends TestCase
{
    /**
     * @dataProvider serviceUrlDataProvider
     */
    public function testServiceUrl(string $service, string $expectedUrl): void
    {
        $storageClient = self::getMockBuilder(StorageClient::class)
            ->onlyMethods(['indexAction'])
            ->disableOriginalConstructor()
            ->getMock();

        $storageClient
            ->method('indexAction')
            ->willReturn([
                'services' => [
                    [
                        'id' => 'scheduler',
                        'url' => 'https://scheduler.keboola.com',
                    ],
                    [
                        'id' => 'queue',
                        'url' => 'https://queue.keboola.com',
                    ],
                    [
                        'id' => 'sync-actions',
                        'url' => 'https://sync-actions.keboola.com',
                    ],
                ],
            ]);

        $queueV2Runner = new QueueV2JobRunner($storageClient, new NullLogger());
        self::assertEquals($expectedUrl, $queueV2Runner->getServiceUrl($service));
    }

    public function testServiceUrlNotFound(): void
    {
        $storageClient = self::getMockBuilder(StorageClient::class)
            ->onlyMethods(['indexAction'])
            ->disableOriginalConstructor()
            ->getMock();

        $storageClient
            ->method('indexAction')
            ->willReturn([
                'services' => [],
            ]);

        $queueV2Runner = new QueueV2JobRunner($storageClient, new NullLogger());

        $this->expectException(Throwable::class);
        $this->expectExceptionMessage('notFound service not found');
        $queueV2Runner->getServiceUrl('notFound');
    }

    public function testServiceUrlCaching(): void
    {
        $storageClient = self::getMockBuilder(StorageClient::class)
            ->onlyMethods(['indexAction'])
            ->disableOriginalConstructor()
            ->getMock();

        $storageClient
            ->expects($this->once())
            ->method('indexAction')
            ->willReturn([
                'services' => [
                    [
                        'id' => 'queue',
                        'url' => 'https://queue.keboola.com',
                    ],
                    [
                        'id' => 'sync-actions',
                        'url' => 'https://sync-actions.keboola.com',
                    ],
                ],
            ]);

        $queueV2Runner = new QueueV2JobRunner($storageClient, new NullLogger());

        // Zavoláme getServiceUrl vícekrát, ale indexAction by se mělo volat pouze jednou
        self::assertEquals('https://queue.keboola.com', $queueV2Runner->getServiceUrl('queue'));
        self::assertEquals('https://sync-actions.keboola.com', $queueV2Runner->getServiceUrl('sync-actions'));
        self::assertEquals('https://queue.keboola.com', $queueV2Runner->getServiceUrl('queue'));
    }

    public function serviceUrlDataProvider(): Generator
    {
        yield 'queue' => [
            'queue',
            'https://queue.keboola.com',
        ];

        yield 'scheduler' => [
            'scheduler',
            'https://scheduler.keboola.com',
        ];

        yield 'sync-actions' => [
            'sync-actions',
            'https://sync-actions.keboola.com',
        ];
    }
}
