<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests;

use Generator;
use Keboola\AppProjectMigrate\Utils;
use Keboola\Component\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    /**
     * @dataProvider checkIfProjectEmptyDataProvider
     */
    public function testCheckIfProjectEmpty(array $componentConfigs, array $storageBuckets, bool $expectedResult): void
    {

        $componentClient = $this->createMock(Components::class);
        $componentClient
            ->method('listComponents')
            ->willReturn($componentConfigs)
        ;

        $storageClientMock = $this->createMock(Client::class);
        $storageClientMock
            ->method('listBuckets')
            ->willReturn($storageBuckets)
        ;

        /** @var Components $componentClient */
        /** @var Client $storageClientMock */
        Assert::assertEquals(Utils::checkIfProjectEmpty($storageClientMock, $componentClient), $expectedResult);
    }

    public function checkIfProjectEmptyDataProvider(): Generator
    {
        yield 'empty project' => [
            [],
            [],
            true,
        ];

        yield 'non-empty component configs' => [
            [
                [
                    [
                        'id' => 'keboola.ex-db-mssql',
                        'type' => 'extractor',
                        'name' => 'Microsoft SQL Server',
                        'configurations' => [
                            [
                                'id' => '672381894',
                                'created' => '2021-02-02T15:04:12+0100',
                                'creatorToken' => [
                                    'id' => 157401,
                                    'description' => 'ondrej.jodas@keboola.com',
                                ],
                                'version' => 1,
                                'changeDescription' => 'Configuration created',
                                'isDeleted' => false,
                            ],
                        ],
                    ],
                ],
            ],
            [],
            false,
        ];

        yield 'non-empty storage buckets' => [
            [],
            [
                [
                    [
                        'id' => 'out.c-prod-optimizer-data',
                        'name' => 'c-prod-optimizer-data',
                        'displayName' => 'prod-optimizer-data',
                        'stage' => 'out',
                        'description' => '',
                        'created' => '2020-10-25T23:20:32+0100',
                        'lastChangeDate' => '2020-10-25T23:21:04+0100',
                        'isReadOnly' => false,
                        'dataSizeBytes' => 11780608,
                        'rowsCount' => 152745,
                        'isMaintenance' => false,
                        'backend' => 'snowflake',
                        'sharing' => null,
                        'directAccessEnabled' => false,
                        'directAccessSchemaName' => null,
                        'attributes' => [],
                    ],
                ],
            ],
            false,
        ];
    }


    public function testMissingSourceApp(): void
    {
        $sourceClientMock = $this->createMock(Client::class);
        $destClientMock = $this->createMock(Client::class);

        $sourceClientMock->method('apiGet')->willReturn(['components' => []]);

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Missing "keboola.project-backup" application in the source project.');
        Utils::checkMigrationApps($sourceClientMock, $destClientMock);
    }

    public function testMissingDestinationApp(): void
    {
        $sourceClientMock = $this->createMock(Client::class);
        $destClientMock = $this->createMock(Client::class);

        $sourceClientMock
            ->method('apiGet')
            ->willReturn(
                [
                    'components' => [
                        ['id' => 'keboola.project-backup'],
                    ],
                ]
            );

        $destClientMock
            ->method('apiGet')
            ->willReturn(
                [
                    'components' => [
                        ['id' => 'keboola.app-orchestrator-migrate'],
                        ['id' => 'keboola.app-snowflake-writer-migrate'],
                    ],
                ]
            );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Missing "keboola.project-restore" application in the destination project.');
        Utils::checkMigrationApps($sourceClientMock, $destClientMock);
    }
}
