<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests;

use Generator;
use Keboola\AppProjectMigrate\Config;
use Keboola\AppProjectMigrate\ConfigDefinition;
use Keboola\AppProjectMigrate\JobRunner\JobRunner;
use Keboola\AppProjectMigrate\JobRunner\QueueV2JobRunner;
use Keboola\AppProjectMigrate\JobRunner\SyrupJobRunner;
use Keboola\AppProjectMigrate\Migrate;
use Keboola\Component\UserException;
use Keboola\EncryptionApiClient\Migrations;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\Syrup\ClientException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MigrateTest extends TestCase
{

    /**
     * @param class-string $jobRunnerClass
     * @dataProvider successMigrateDataProvider
     * @throws UserException
     */
    public function testMigrateSuccess(
        array $expectedCredentialsData,
        string $jobRunnerClass,
        bool $migrateDataOfTablesDirectly,
        int $expectsRunJobs,
        bool $restoreConfigs
    ): void {
        $sourceJobRunnerMock = $this->createMock($jobRunnerClass);
        $destJobRunnerMock = $this->createMock($jobRunnerClass);

        // generate credentials
        if (array_key_exists('abs', $expectedCredentialsData)) {
            $this->mockAddMethodGenerateAbsReadCredentials($sourceJobRunnerMock);
        } else {
            $this->mockAddMethodGenerateS3ReadCredentials($sourceJobRunnerMock);
        }
        $this->mockAddMethodBackupProject(
            $sourceJobRunnerMock,
            [
                'id' => '222',
                'status' => 'success',
            ],
            $migrateDataOfTablesDirectly
        );

        $sourceProjectUrl = 'https://connection.keboola.com';
        $sourceProjectToken = 'xyz';

        $destinationMockJobs = [
            // restore data
            [
                Config::PROJECT_RESTORE_COMPONENT,
                [
                    'parameters' => array_merge(
                        $expectedCredentialsData,
                        [
                            'useDefaultBackend' => true,
                            'restoreConfigs' => $restoreConfigs,
                        ]
                    ),
                ],
            ],
        ];

        // migrate data of tables
        if ($migrateDataOfTablesDirectly) {
            $destinationMockJobs[] = [
                Config::DATA_OF_TABLES_MIGRATE_COMPONENT,
                [
                    'parameters' => [
                        'mode' => 'sapi',
                        'sourceKbcUrl' => $sourceProjectUrl,
                        '#sourceKbcToken' => $sourceProjectToken,
                    ],
                ],
            ];
        }

        // restore snowflake writers
        $destinationMockJobs[] = [
            Config::SNOWFLAKE_WRITER_MIGRATE_COMPONENT,
            [
                'parameters' => [
                    'sourceKbcUrl' => $sourceProjectUrl,
                    '#sourceKbcToken' => $sourceProjectToken,
                ],
            ],
        ];

        // restore orchestrations
        $destinationMockJobs[] = [
            Config::ORCHESTRATOR_MIGRATE_COMPONENT,
            [
                'parameters' => [
                    'sourceKbcUrl' => $sourceProjectUrl,
                    '#sourceKbcToken' => $sourceProjectToken,
                ],
            ],
        ];

        // run restore with credentials from step 1
        $destJobRunnerMock->expects($this->exactly($expectsRunJobs))
            ->method('runJob')
            ->withConsecutive(...$destinationMockJobs)->willReturn([
                'id' => '222',
                'status' => 'success',
            ]);

        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => $sourceProjectUrl,
                    '#sourceKbcToken' => $sourceProjectToken,
                    'migrateSecrets' => false,
                    'directDataMigration' => $migrateDataOfTablesDirectly,
                    '#sourceManageToken' => 'manage-token',
                ],
            ],
            new ConfigDefinition()
        );

        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);

        $sourceClientMock = $this->createMock(StorageClient::class);
        $sourceClientMock
            ->method('apiGet')
            ->willReturnMap([
                [
                    'dev-branches/', null, [],
                    [
                        [
                            'id' => '123',
                            'name' => 'default',
                            'isDefault' => true,
                        ],
                    ],
                ],
                [
                    'components?include=', null, [],
                    [
                        [
                            'id' => 'gooddata-writer', // should be skipped
                        ],
                        [
                            'id' => 'some-component',
                            'configurations' => [
                                [
                                    'id' => '101',
                                ],
                                [
                                    'id' => '102',
                                ],
                            ],
                        ],
                        [
                            'id' => 'another-component',
                            'configurations' => [
                                [
                                    'id' => '201',
                                ],
                            ],
                        ],
                    ],
                ],
            ])
        ;
        $sourceClientMock
            ->method('getServiceUrl')
            ->with('encryption')
            ->willReturn('https://encryption.keboola.com')
        ;

        $migrationsClientMock = $this->createMock(Migrations::class);
        $migrationsClientMock->expects(self::never())->method('migrateConfiguration');

        /** @var JobRunner $sourceJobRunnerMock */
        /** @var JobRunner $destJobRunnerMock */
        $migrate = new Migrate(
            $config,
            $sourceJobRunnerMock,
            $destJobRunnerMock,
            $sourceClientMock,
            $migrationsClientMock,
            'https://dest-stack/',
            'dest-token',
            $logger,
        );

        $migrate->run();
    }

    public function testMigrateSecretsSuccess(): void
    {
        $sourceJobRunnerMock = $this->createMock(QueueV2JobRunner::class);
        $destJobRunnerMock = $this->createMock(QueueV2JobRunner::class);

        // generate credentials
        $this->mockAddMethodGenerateAbsReadCredentials($sourceJobRunnerMock);
        $this->mockAddMethodBackupProject(
            $sourceJobRunnerMock,
            [
                'id' => '222',
                'status' => 'success',
            ],
            true
        );

        $destJobRunnerMock->method('runJob')
            ->willReturn([
                'id' => '222',
                'status' => 'success',
            ]);

        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'xyz',
                    'migrateSecrets' => true,
                    '#sourceManageToken' => 'manage-token',
                ],
            ],
            new ConfigDefinition()
        );

        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);

        $sourceClientMock = $this->createMock(StorageClient::class);
        $sourceClientMock
            ->method('apiGet')
            ->willReturnMap([
                [
                    'dev-branches/', null, [],
                    [
                        [
                            'id' => '123',
                            'name' => 'default',
                            'isDefault' => true,
                        ],
                    ],
                ],
                [
                    'components?include=', null, [],
                    [
                        [
                            'id' => 'gooddata-writer', // should be skipped
                        ],
                        [
                            'id' => 'some-component',
                            'configurations' => [
                                [
                                    'id' => '101',
                                ],
                                [
                                    'id' => '102',
                                ],
                            ],
                        ],
                        [
                            'id' => 'another-component',
                            'configurations' => [
                                [
                                    'id' => '201',
                                ],
                            ],
                        ],
                    ],
                ],
            ])
        ;
        $sourceClientMock
            ->method('getServiceUrl')
            ->with('encryption')
            ->willReturn('https://encryption.keboola.com')
        ;

        $migrationsClientMock = $this->createMock(Migrations::class);
        $migrationsClientMock
            ->expects(self::exactly(3))
            ->method('migrateConfiguration')
            ->willReturnCallback(function (...$args) {
                [, $destinationStack, , , $configId] = $args;
                return [
                    'message' => "Configuration with ID '$configId' successfully " .
                        "migrated to stack '$destinationStack'.",
                    'data' => [],
                ];
            });

        /** @var JobRunner $sourceJobRunnerMock */
        /** @var JobRunner $destJobRunnerMock */
        $migrate = new Migrate(
            $config,
            $sourceJobRunnerMock,
            $destJobRunnerMock,
            $sourceClientMock,
            $migrationsClientMock,
            'https://dest-stack/',
            'dest-token',
            $logger,
        );

        $migrate->run();

        $records = array_filter(
            $logsHandler->getRecords(),
            fn(array $record) => in_array('secrets', $record['context'] ?? [], true)
        );
        self::assertCount(5, $records);

        $record = array_shift($records);
        self::assertSame('Migrating configurations with secrets', $record['message']);
        $record = array_shift($records);
        self::assertSame('Components "gooddata-writer" is obsolete, skipping migration...', $record['message']);
        $record = array_shift($records);
        self::assertSame(
            'Configuration with ID \'101\' successfully migrated to stack \'dest-stack\'.',
            $record['message']
        );
        $record = array_shift($records);
        self::assertSame(
            'Configuration with ID \'102\' successfully migrated to stack \'dest-stack\'.',
            $record['message']
        );
        $record = array_shift($records);
        self::assertSame(
            'Configuration with ID \'201\' successfully migrated to stack \'dest-stack\'.',
            $record['message']
        );
    }

    public function testShouldFailOnSnapshotError(): void
    {
        $sourceJobRunnerMock = $this->createMock(SyrupJobRunner::class);
        $destJobRunnerMock = $this->createMock(SyrupJobRunner::class);
        $sourceClientMock = $this->createMock(StorageClient::class);
        $migrationsClientMock = $this->createMock(Migrations::class);

        // generate credentials
        $this->mockAddMethodGenerateS3ReadCredentials($sourceJobRunnerMock);
        $this->mockAddMethodBackupProject(
            $sourceJobRunnerMock,
            [
                'id' => '222',
                'status' => 'error',
                'result' => [
                    'message' => 'Cannot snapshot project',
                ],
            ],
            false
        );

        $destJobRunnerMock->expects($this->never())
            ->method('runJob');

        $this->expectException(UserException::class);
        $this->expectExceptionMessageMatches('/Cannot snapshot project/');

        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'xxx',
                    '#sourceKbcToken' => 'yyy',
                    'migrateSecrets' => false,
                    'directDataMigration' => false,
                ],
            ],
            new ConfigDefinition()
        );

        $migrate = new Migrate(
            $config,
            $sourceJobRunnerMock,
            $destJobRunnerMock,
            $sourceClientMock,
            $migrationsClientMock,
            'xxx-b',
            'yyy-b',
            new NullLogger(),
        );
        $migrate->run();
    }

    public function testShouldFailOnRestoreError(): void
    {
        $sourceJobRunnerMock = $this->createMock(SyrupJobRunner::class);
        $destJobRunnerMock = $this->createMock(SyrupJobRunner::class);
        $sourceClientMock = $this->createMock(StorageClient::class);
        $migrationsClientMock = $this->createMock(Migrations::class);

        $this->mockAddMethodGenerateS3ReadCredentials($sourceJobRunnerMock);
        $this->mockAddMethodBackupProject(
            $sourceJobRunnerMock,
            [
            'id' => '222',
                'status' => 'success',
            ],
            false
        );

        $destJobRunnerMock
            ->method('runJob')
            ->willReturn([
                'id' => '222',
                'status' => 'error',
                'result' => [
                    'message' => 'Cannot restore project',
                ],
            ]);

        $this->expectException(UserException::class);
        $this->expectExceptionMessageMatches('/Cannot restore project/');

        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'xxx',
                    '#sourceKbcToken' => 'yyy',
                    'migrateSecrets' => false,
                    'directDataMigration' => false,
                ],
            ],
            new ConfigDefinition()
        );

        $migrate = new Migrate(
            $config,
            $sourceJobRunnerMock,
            $destJobRunnerMock,
            $sourceClientMock,
            $migrationsClientMock,
            'xxx-b',
            'yyy-b',
            new NullLogger(),
        );
        $migrate->run();
    }

    public function testCatchSyrupClientException(): void
    {
        $sourceJobRunnerMock = $this->createMock(SyrupJobRunner::class);
        $destJobRunnerMock = $this->createMock(SyrupJobRunner::class);
        $sourceClientMock = $this->createMock(StorageClient::class);
        $migrationsClientMock = $this->createMock(Migrations::class);

        $this->mockAddMethodGenerateS3ReadCredentials($sourceJobRunnerMock);
        $this->mockAddMethodBackupProject(
            $sourceJobRunnerMock,
            [
                'id' => '222',
                'status' => 'success',
            ],
            false
        );

        $destJobRunnerMock
            ->method('runJob')
            ->willThrowException(
                new ClientException('Test ClientException', 401)
            )
        ;

        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'xxx',
                    '#sourceKbcToken' => 'yyy',
                    'migrateSecrets' => false,
                    'directDataMigration' => false,
                ],
            ],
            new ConfigDefinition()
        );

        $migrate = new Migrate(
            $config,
            $sourceJobRunnerMock,
            $destJobRunnerMock,
            $sourceClientMock,
            $migrationsClientMock,
            'xxx-b',
            'yyy-b',
            new NullLogger(),
        );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Test ClientException');
        $this->expectExceptionCode(401);
        $migrate->run();
    }

    private function mockAddMethodGenerateS3ReadCredentials(MockObject $mockObject): void
    {
        $mockObject->expects($this->once())
            ->method('runSyncAction')
            ->with(
                Config::PROJECT_BACKUP_COMPONENT,
                'generate-read-credentials',
                [
                    'parameters' => [
                        'backupId' => null,
                    ],
                ]
            )
            ->willReturn(
                [
                    'backupId' => '123',
                    'backupUri' => 'https://kbc.s3.amazonaws.com/data-takeout/us-east-1/4788/395904684/',
                    'region' => 'us-east-1',
                    'credentials' => [
                        'accessKeyId' => 'xxx',
                        'secretAccessKey' => 'yyy',
                        'sessionToken' => 'zzz',
                        'expiration' => '2018-05-23T10:49:02+00:00',
                    ],
                ]
            )
        ;
    }

    private function mockAddMethodGenerateAbsReadCredentials(MockObject $mockObject): void
    {
        $mockObject->expects($this->once())
            ->method('runSyncAction')
            ->with(
                Config::PROJECT_BACKUP_COMPONENT,
                'generate-read-credentials',
                [
                    'parameters' => [
                        'backupId' => null,
                    ],
                ]
            )
            ->willReturn(
                [
                    'backupId' => '123',
                    'container' => 'abcdefgh',
                    'credentials' => [
                        'connectionString' => 'https://testConnectionString',
                    ],
                ]
            )
        ;
    }

    private function mockAddMethodBackupProject(MockObject $mockObject, array $return, bool $exportStructureOnly): void
    {
        $mockObject
            ->method('runJob')
            ->with(
                Config::PROJECT_BACKUP_COMPONENT,
                [
                    'parameters' => [
                        'backupId' => '123',
                        'exportStructureOnly' => $exportStructureOnly,
                    ],
                ]
            )
            ->willReturn($return)
        ;
    }

    public function successMigrateDataProvider(): Generator
    {
        yield 'migrate-S3-syrup' => [
            'expectedCredentialsData' => [
                's3' => [
                    'backupUri' => 'https://kbc.s3.amazonaws.com/data-takeout/us-east-1/4788/395904684/',
                    'accessKeyId' => 'xxx',
                    '#secretAccessKey' => 'yyy',
                    '#sessionToken' => 'zzz',
                ],
            ],
            'jobRunnerClass' => SyrupJobRunner::class,
            'migrateDataOfTablesDirectly' => false,
            'expectsRunJobs' => 3,
            'restoreConfigs' => true,
        ];

        yield 'migrate-ABS-syrup' => [
            'expectedCredentialsData' => [
                'abs' => [
                    'container' => 'abcdefgh',
                    '#connectionString' => 'https://testConnectionString',
                ],
            ],
            'jobRunnerClass' => SyrupJobRunner::class,
            'migrateDataOfTablesDirectly' => false,
            'expectsRunJobs' => 3,
            'restoreConfigs' => true,
        ];

        yield 'migrate-S3-queuev2' => [
            'expectedCredentialsData' => [
                's3' => [
                    'backupUri' => 'https://kbc.s3.amazonaws.com/data-takeout/us-east-1/4788/395904684/',
                    'accessKeyId' => 'xxx',
                    '#secretAccessKey' => 'yyy',
                    '#sessionToken' => 'zzz',
                ],
            ],
            'jobRunnerClass' => QueueV2JobRunner::class,
            'migrateDataOfTablesDirectly' => false,
            'expectsRunJobs' => 2,
            'restoreConfigs' => true,
        ];

        yield 'migrateABS-queuev2' => [
            'expectedCredentialsData' => [
                'abs' => [
                    'container' => 'abcdefgh',
                    '#connectionString' => 'https://testConnectionString',
                ],
            ],
            'jobRunnerClass' => QueueV2JobRunner::class,
            'migrateDataOfTablesDirectly' => false,
            'expectsRunJobs' => 2,
            'restoreConfigs' => true,
        ];

        yield 'migrateABS-queuev2-data-directly' => [
            'expectedCredentialsData' => [
                'abs' => [
                    'container' => 'abcdefgh',
                    '#connectionString' => 'https://testConnectionString',
                ],
            ],
            'jobRunnerClass' => QueueV2JobRunner::class,
            'migrateDataOfTablesDirectly' => true,
            'expectsRunJobs' => 3,
            'restoreConfigs' => true,
        ];

        yield 'migrate-secrets-false' => [
            'expectedCredentialsData' => [
                'abs' => [
                    'container' => 'abcdefgh',
                    '#connectionString' => 'https://testConnectionString',
                ],
            ],
            'jobRunnerClass' => QueueV2JobRunner::class,
            'migrateDataOfTablesDirectly' => true,
            'expectsRunJobs' => 3,
            'restoreConfigs' => true,
        ];
    }
}
