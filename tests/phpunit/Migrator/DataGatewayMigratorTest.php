<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests\Migrator;

use Keboola\AppProjectMigrate\Config;
use Keboola\AppProjectMigrate\Migrator\DataGatewayMigrator;
use Keboola\StorageApi\Client as StorageClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DataGatewayMigratorTest extends TestCase
{
    public function testNoDataGatewayConfigurations(): void
    {
        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);

        /** @var StorageClient&MockObject $storageClientMock */
        $storageClientMock = $this->createMock(StorageClient::class);
        $storageClientMock
            ->method('apiGet')
            ->willReturnCallback(function (string $url): array {
                if (str_contains($url, 'components?')) {
                    return []; // No components
                }
                return [];
            });

        $migrator = new DataGatewayMigrator($storageClientMock, $logger);
        $migrator->migrate();

        $messages = array_map(fn(LogRecord $r) => $r->message, $logsHandler->getRecords());
        self::assertContains('Migrating Data Gateway configurations', $messages);
        self::assertContains('No Data Gateway configurations found.', $messages);
    }

    public function testNoDataGatewayConfigurationsWhenComponentExistsButEmpty(): void
    {
        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);

        /** @var StorageClient&MockObject $storageClientMock */
        $storageClientMock = $this->createMock(StorageClient::class);
        $storageClientMock
            ->method('apiGet')
            ->willReturnCallback(function (string $url): array {
                if (str_contains($url, 'components?')) {
                    return [
                        [
                            'id' => Config::DATA_GATEWAY_COMPONENT,
                            'configurations' => [], // Empty configurations
                        ],
                    ];
                }
                return [];
            });

        $migrator = new DataGatewayMigrator($storageClientMock, $logger);
        $migrator->migrate();

        $messages = array_map(fn(LogRecord $r) => $r->message, $logsHandler->getRecords());
        self::assertContains('No Data Gateway configurations found.', $messages);
    }

    public function testDryRunMode(): void
    {
        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);

        /** @var StorageClient&MockObject $storageClientMock */
        $storageClientMock = $this->createMock(StorageClient::class);
        $storageClientMock
            ->method('apiGet')
            ->willReturnCallback(function (string $url): array {
                if (str_contains($url, 'components?')) {
                    return [
                        [
                            'id' => Config::DATA_GATEWAY_COMPONENT,
                            'configurations' => [
                                ['id' => 'config-1'],
                                ['id' => 'config-2'],
                            ],
                        ],
                    ];
                }
                return [];
            });

        // In dry-run mode, no workspace should be created
        $storageClientMock->expects(self::never())->method('apiPostJson');

        $migrator = new DataGatewayMigrator($storageClientMock, $logger, dryRun: true);
        $migrator->migrate();

        $messages = array_map(fn(LogRecord $r) => $r->message, $logsHandler->getRecords());
        self::assertContains('[dry-run] Would migrate Data Gateway config "config-1"', $messages);
        self::assertContains('[dry-run] Would migrate Data Gateway config "config-2"', $messages);
        self::assertContains('Data Gateway configurations migrated', $messages);
    }

    public function testMigrateSingleConfiguration(): void
    {
        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);

        $configData = [
            'id' => 'config-1',
            'name' => 'My Data Gateway',
            'configuration' => [
                'parameters' => [
                    'db' => [
                        'workspaceId' => 100,
                        'host' => 'old-host.snowflake.com',
                        'user' => 'OLD_USER',
                    ],
                ],
            ],
        ];

        $newWorkspaceData = [
            'id' => 200,
            'connection' => [
                'host' => 'new-host.snowflake.com',
                'user' => 'NEW_USER',
                'schema' => 'NEW_SCHEMA',
                'warehouse' => 'NEW_WAREHOUSE',
                'database' => 'NEW_DATABASE',
                'role' => 'NEW_ROLE',
            ],
        ];

        $updatedConfiguration = null;

        /** @var StorageClient&MockObject $storageClientMock */
        $storageClientMock = $this->createMock(StorageClient::class);
        $storageClientMock
            ->method('apiGet')
            ->willReturnCallback(function (string $url) use ($configData): array {
                if (str_contains($url, 'components?')) {
                    return [
                        [
                            'id' => Config::DATA_GATEWAY_COMPONENT,
                            'configurations' => [
                                ['id' => 'config-1'],
                            ],
                        ],
                    ];
                }
                if (str_contains($url, 'configs/config-1')) {
                    return $configData;
                }
                return [];
            });

        $storageClientMock
            ->method('apiPostJson')
            ->willReturnCallback(
                function (string $url) use ($newWorkspaceData): array {
                    if ($url === 'workspaces') {
                        return $newWorkspaceData;
                    }
                    if (str_contains($url, 'public-key')) {
                        return [];
                    }
                    return [];
                },
            );

        $storageClientMock
            ->method('apiPutJson')
            ->willReturnCallback(function (string $url, array $data) use (&$updatedConfiguration): array {
                if (str_contains($url, 'configs/config-1')) {
                    $updatedConfiguration = $data;
                }
                return [];
            });

        $migrator = new DataGatewayMigrator($storageClientMock, $logger);
        $migrator->migrate();

        $messages = array_map(fn(LogRecord $r) => $r->message, $logsHandler->getRecords());
        self::assertContains('Migrating Data Gateway config "config-1"', $messages);
        self::assertContains('Data Gateway config "config-1" migrated to workspace 200', $messages);
        self::assertContains(
            'Data Gateway workspace data NOT migrated for config "config-1". User must load data manually.',
            $messages,
        );

        // Verify configuration was updated with new workspace credentials
        self::assertNotNull($updatedConfiguration);
        self::assertArrayHasKey('configuration', $updatedConfiguration);
        /** @var array{configuration: array{parameters: array{db: array<string, mixed>}}} $updatedConfiguration */
        $db = $updatedConfiguration['configuration']['parameters']['db'];
        self::assertSame('new-host.snowflake.com', $db['host']);
        self::assertSame('NEW_USER', $db['user']);
        self::assertSame('NEW_SCHEMA', $db['schema']);
        self::assertSame('NEW_WAREHOUSE', $db['warehouse']);
        self::assertSame('NEW_DATABASE', $db['database']);
        self::assertSame(200, $db['workspaceId']);
        self::assertSame('snowflake-service-keypair', $db['loginType']);
        self::assertArrayHasKey('#privateKey', $db);
        self::assertIsString($db['#privateKey']);
        self::assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $db['#privateKey']);
    }

    public function testMigrateMultipleConfigurationsWithSharedWorkspace(): void
    {
        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);

        $sharedWorkspaceId = 100;

        $configData1 = [
            'id' => 'config-1',
            'name' => 'Data Gateway 1',
            'configuration' => [
                'parameters' => [
                    'db' => ['workspaceId' => $sharedWorkspaceId],
                ],
            ],
        ];

        $configData2 = [
            'id' => 'config-2',
            'name' => 'Data Gateway 2',
            'configuration' => [
                'parameters' => [
                    'db' => ['workspaceId' => $sharedWorkspaceId], // Same workspace
                ],
            ],
        ];

        $newWorkspaceData = [
            'id' => 200,
            'connection' => [
                'host' => 'new-host.snowflake.com',
                'user' => 'NEW_USER',
                'schema' => 'NEW_SCHEMA',
                'warehouse' => 'NEW_WAREHOUSE',
                'database' => 'NEW_DATABASE',
                'role' => null,
            ],
        ];

        $workspaceCreateCount = 0;

        /** @var StorageClient&MockObject $storageClientMock */
        $storageClientMock = $this->createMock(StorageClient::class);
        $storageClientMock
            ->method('apiGet')
            ->willReturnCallback(function (string $url) use ($configData1, $configData2): array {
                if (str_contains($url, 'components?')) {
                    return [
                        [
                            'id' => Config::DATA_GATEWAY_COMPONENT,
                            'configurations' => [
                                ['id' => 'config-1'],
                                ['id' => 'config-2'],
                            ],
                        ],
                    ];
                }
                if (str_contains($url, 'configs/config-1')) {
                    return $configData1;
                }
                if (str_contains($url, 'configs/config-2')) {
                    return $configData2;
                }
                return [];
            });

        $storageClientMock
            ->method('apiPostJson')
            ->willReturnCallback(function (string $url) use ($newWorkspaceData, &$workspaceCreateCount): array {
                if ($url === 'workspaces') {
                    $workspaceCreateCount++;
                    return $newWorkspaceData;
                }
                return [];
            });

        $storageClientMock->method('apiPutJson')->willReturn([]);

        $migrator = new DataGatewayMigrator($storageClientMock, $logger);
        $migrator->migrate();

        // Only one workspace should be created (second config reuses the first)
        self::assertSame(1, $workspaceCreateCount);

        $messages = array_map(fn(LogRecord $r) => $r->message, $logsHandler->getRecords());
        self::assertContains('Migrating Data Gateway config "config-1"', $messages);
        self::assertContains('Reusing already migrated workspace 200 for config "config-2"', $messages);
    }

    public function testMigrateConfigurationWithoutWorkspaceId(): void
    {
        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);

        $configData = [
            'id' => 'config-1',
            'name' => 'Data Gateway without workspace',
            'configuration' => [
                'parameters' => [
                    'db' => [
                        'host' => 'some-host.snowflake.com',
                        // No workspaceId
                    ],
                ],
            ],
        ];

        $newWorkspaceData = [
            'id' => 200,
            'connection' => [
                'host' => 'new-host.snowflake.com',
                'user' => 'NEW_USER',
                'schema' => 'NEW_SCHEMA',
                'warehouse' => 'NEW_WAREHOUSE',
                'database' => 'NEW_DATABASE',
                'role' => null,
            ],
        ];

        /** @var StorageClient&MockObject $storageClientMock */
        $storageClientMock = $this->createMock(StorageClient::class);
        $storageClientMock
            ->method('apiGet')
            ->willReturnCallback(function (string $url) use ($configData): array {
                if (str_contains($url, 'components?')) {
                    return [
                        [
                            'id' => Config::DATA_GATEWAY_COMPONENT,
                            'configurations' => [
                                ['id' => 'config-1'],
                            ],
                        ],
                    ];
                }
                if (str_contains($url, 'configs/config-1')) {
                    return $configData;
                }
                return [];
            });

        $storageClientMock
            ->method('apiPostJson')
            ->willReturnCallback(function (string $url) use ($newWorkspaceData): array {
                if ($url === 'workspaces') {
                    return $newWorkspaceData;
                }
                return [];
            });

        $storageClientMock->method('apiPutJson')->willReturn([]);

        $migrator = new DataGatewayMigrator($storageClientMock, $logger);
        $migrator->migrate();

        $messages = array_map(fn(LogRecord $r) => $r->message, $logsHandler->getRecords());
        self::assertContains('Migrating Data Gateway config "config-1"', $messages);
        self::assertContains('Data Gateway config "config-1" migrated to workspace 200', $messages);
    }

    public function testMigrateMultipleConfigurationsWithDifferentWorkspaces(): void
    {
        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);

        $configData1 = [
            'id' => 'config-1',
            'name' => 'Data Gateway 1',
            'configuration' => [
                'parameters' => [
                    'db' => ['workspaceId' => 100],
                ],
            ],
        ];

        $configData2 = [
            'id' => 'config-2',
            'name' => 'Data Gateway 2',
            'configuration' => [
                'parameters' => [
                    'db' => ['workspaceId' => 101], // Different workspace
                ],
            ],
        ];

        $workspaceCreateCount = 0;

        /** @var StorageClient&MockObject $storageClientMock */
        $storageClientMock = $this->createMock(StorageClient::class);
        $storageClientMock
            ->method('apiGet')
            ->willReturnCallback(function (string $url) use ($configData1, $configData2): array {
                if (str_contains($url, 'components?')) {
                    return [
                        [
                            'id' => Config::DATA_GATEWAY_COMPONENT,
                            'configurations' => [
                                ['id' => 'config-1'],
                                ['id' => 'config-2'],
                            ],
                        ],
                    ];
                }
                if (str_contains($url, 'configs/config-1')) {
                    return $configData1;
                }
                if (str_contains($url, 'configs/config-2')) {
                    return $configData2;
                }
                return [];
            });

        $storageClientMock
            ->method('apiPostJson')
            ->willReturnCallback(function (string $url) use (&$workspaceCreateCount): array {
                if ($url === 'workspaces') {
                    $workspaceCreateCount++;
                    return [
                        'id' => 200 + $workspaceCreateCount,
                        'connection' => [
                            'host' => 'host.snowflake.com',
                            'user' => 'USER',
                            'schema' => 'SCHEMA',
                            'warehouse' => 'WAREHOUSE',
                            'database' => 'DATABASE',
                            'role' => null,
                        ],
                    ];
                }
                return [];
            });

        $storageClientMock->method('apiPutJson')->willReturn([]);

        $migrator = new DataGatewayMigrator($storageClientMock, $logger);
        $migrator->migrate();

        // Two workspaces should be created (different source workspaceIds)
        self::assertSame(2, $workspaceCreateCount);

        $messages = array_map(fn(LogRecord $r) => $r->message, $logsHandler->getRecords());
        self::assertContains('Data Gateway config "config-1" migrated to workspace 201', $messages);
        self::assertContains('Data Gateway config "config-2" migrated to workspace 202', $messages);
    }
}
