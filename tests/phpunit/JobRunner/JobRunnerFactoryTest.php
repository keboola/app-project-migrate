<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests\JobRunner;

use Generator;
use Keboola\AppProjectMigrate\JobRunner\JobRunnerFactory;
use Keboola\AppProjectMigrate\JobRunner\QueueV2JobRunner;
use Keboola\StorageApi\Client as StorageClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class JobRunnerFactoryTest extends TestCase
{
    /**
     * @dataProvider jobRunnerFactoryDataProvider
     * @param array<int, string> $features
     * @param class-string $expectedClass
     */
    public function testJobRunnerFactory(array $features, string $expectedClass): void
    {
        $storageClient = self::getMockBuilder(StorageClient::class)
            ->onlyMethods(['verifyToken'])
            ->disableOriginalConstructor()
            ->getMock();

        $storageClient
            ->expects($this->once())
            ->method('verifyToken')
            ->willReturn([
                'owner' => [
                    'features' => $features,
                ],
            ]);

        $jobRunner = JobRunnerFactory::create($storageClient, new NullLogger());

        self::assertInstanceOf($expectedClass, $jobRunner);
    }

    /**
     * @dataProvider jobRunnerFactoryExceptionDataProvider
     * @param array<int, string> $features
     */
    public function testJobRunnerFactoryThrowsException(array $features): void
    {
        $storageClient = self::getMockBuilder(StorageClient::class)
            ->onlyMethods(['verifyToken'])
            ->disableOriginalConstructor()
            ->getMock();

        $storageClient
            ->expects($this->once())
            ->method('verifyToken')
            ->willReturn([
                'owner' => [
                    'features' => $features,
                ],
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No suitable JobRunner found for the provided Storage API token.');

        JobRunnerFactory::create($storageClient, new NullLogger());
    }

    public function jobRunnerFactoryDataProvider(): Generator
    {
        yield 'queuev2 feature' => [
            ['queuev2'],
            QueueV2JobRunner::class,
        ];

        yield 'queuev2 with other features' => [
            ['queuev2', 'other-feature', 'another-feature'],
            QueueV2JobRunner::class,
        ];
    }

    public function jobRunnerFactoryExceptionDataProvider(): Generator
    {
        yield 'empty features' => [
            [],
        ];

        yield 'other features without queuev2' => [
            ['other-feature', 'another-feature'],
        ];

        yield 'single other feature' => [
            ['some-feature'],
        ];
    }
}
