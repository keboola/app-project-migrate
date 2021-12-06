<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests;

use Generator;
use Keboola\AppProjectMigrate\Config;
use Keboola\AppProjectMigrate\JobRunner\JobRunner;
use Keboola\AppProjectMigrate\JobRunner\QueueV2JobRunner;
use Keboola\AppProjectMigrate\JobRunner\SyrupJobRunner;
use Keboola\AppProjectMigrate\Migrate;
use Keboola\Component\UserException;
use Keboola\Syrup\ClientException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MigrateTest extends TestCase
{

    /**
     * @param class-string $jobRunnerClass
     * @dataProvider successMigrateDataProvider
     */
    public function testMigrateSuccess(
        array $expectedCredentialsData,
        string $jobRunnerClass,
        int $expectsRunJobs
    ): void {
        $sourceClientMock = $this->createMock($jobRunnerClass);
        $destClientMock = $this->createMock($jobRunnerClass);

        // generate credentials
        if (array_key_exists('abs', $expectedCredentialsData)) {
            $this->mockAddMethodGenerateAbsReadCredentials($sourceClientMock);
        } else {
            $this->mockAddMethodGenerateS3ReadCredentials($sourceClientMock);
        }
        $this->mockAddMethodBackupProject(
            $sourceClientMock,
            [
                'id' => '222',
                'status' => 'success',
            ]
        );

        $sourceProjectUrl = 'https://connection.keboola.com';
        $sourceProjectToken = 'xyz';

        // run restore with credentials from step 1
        $destClientMock->expects($this->exactly($expectsRunJobs))
            ->method('runJob')
            ->withConsecutive(
            // restore data
                [
                    Config::PROJECT_RESTORE_COMPONENT,
                    [
                        'parameters' => array_merge($expectedCredentialsData, ['useDefaultBackend' => true]),
                    ],
                ],
                // restore snowflake writers
                [
                    Config::SNOWFLAKE_WRITER_MIGRATE_COMPONENT,
                    [
                        'parameters' => [
                            'sourceKbcUrl' => $sourceProjectUrl,
                            '#sourceKbcToken' => $sourceProjectToken,
                        ],
                    ],
                ],
                // restore orchestrations
                [
                    Config::ORCHESTRATOR_MIGRATE_COMPONENT,
                    [
                        'parameters' => [
                            'sourceKbcUrl' => $sourceProjectUrl,
                            '#sourceKbcToken' => $sourceProjectToken,
                        ],
                    ],
                ]
            )->willReturn([
                'id' => '222',
                'status' => 'success',
            ]);

        /** @var JobRunner $sourceClientMock */
        /** @var JobRunner $destClientMock */
        $migrate = new Migrate(
            $sourceClientMock,
            $destClientMock,
            $sourceProjectUrl,
            $sourceProjectToken,
            new NullLogger()
        );
        $migrate->run();
    }

    public function testShouldFailOnSnapshotError(): void
    {
        $sourceClientMock = $this->createMock(SyrupJobRunner::class);
        $destClientMock = $this->createMock(SyrupJobRunner::class);

        // generate credentials
        $this->mockAddMethodGenerateS3ReadCredentials($sourceClientMock);
        $this->mockAddMethodBackupProject(
            $sourceClientMock,
            [
                'id' => '222',
                'status' => 'error',
                'result' => [
                    'message' => 'Cannot snapshot project',
                ],
            ]
        );

        $destClientMock->expects($this->never())
            ->method('runJob');

        $this->expectException(UserException::class);
        $this->expectExceptionMessageMatches('/Cannot snapshot project/');
        $migrate = new Migrate(
            $sourceClientMock,
            $destClientMock,
            'xxx',
            'yyy',
            new NullLogger()
        );
        $migrate->run();
    }

    public function testShouldFailOnRestoreError(): void
    {
        $sourceClientMock = $this->createMock(SyrupJobRunner::class);
        $destClientMock = $this->createMock(SyrupJobRunner::class);

        $this->mockAddMethodGenerateS3ReadCredentials($sourceClientMock);
        $this->mockAddMethodBackupProject(
            $sourceClientMock,
            [
            'id' => '222',
                'status' => 'success',
            ]
        );

        $destClientMock->expects($this->any())
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
        $migrate = new Migrate(
            $sourceClientMock,
            $destClientMock,
            'xxx',
            'yyy',
            new NullLogger()
        );
        $migrate->run();
    }

    public function testCatchSyrupClientException(): void
    {
        $sourceClientMock = $this->createMock(SyrupJobRunner::class);
        $destinationClientMock = $this->createMock(SyrupJobRunner::class);

        $this->mockAddMethodGenerateS3ReadCredentials($sourceClientMock);
        $this->mockAddMethodBackupProject(
            $sourceClientMock,
            [
                'id' => '222',
                'status' => 'success',
            ]
        );

        $destinationClientMock
            ->method('runJob')
            ->willThrowException(
                new ClientException('Test ClientException', 401)
            )
        ;

        $migrate = new Migrate(
            $sourceClientMock,
            $destinationClientMock,
            'xxx',
            'yyy',
            new NullLogger()
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

    private function mockAddMethodBackupProject(MockObject $mockObject, array $return): void
    {
        $mockObject
            ->method('runJob')
            ->with(
                Config::PROJECT_BACKUP_COMPONENT,
                [
                    'parameters' => [
                        'backupId' => '123',
                    ],
                ]
            )
            ->willReturn($return)
        ;
    }

    public function successMigrateDataProvider(): Generator
    {
        yield 'migrate-S3-syrup' => [
            [
                's3' => [
                    'backupUri' => 'https://kbc.s3.amazonaws.com/data-takeout/us-east-1/4788/395904684/',
                    'accessKeyId' => 'xxx',
                    '#secretAccessKey' => 'yyy',
                    '#sessionToken' => 'zzz',
                ],
            ],
            SyrupJobRunner::class,
            3,
        ];

        yield 'migrate-ABS-syrup' => [
            [
                'abs' => [
                    'container' => 'abcdefgh',
                    '#connectionString' => 'https://testConnectionString',
                ],
            ],
            SyrupJobRunner::class,
            3,
        ];

        yield 'migrate-S3-queuev2' => [
            [
                's3' => [
                    'backupUri' => 'https://kbc.s3.amazonaws.com/data-takeout/us-east-1/4788/395904684/',
                    'accessKeyId' => 'xxx',
                    '#secretAccessKey' => 'yyy',
                    '#sessionToken' => 'zzz',
                ],
            ],
            QueueV2JobRunner::class,
            2,
        ];

        yield 'migrateABS-queuev2' => [
            [
                'abs' => [
                    'container' => 'abcdefgh',
                    '#connectionString' => 'https://testConnectionString',
                ],
            ],
            QueueV2JobRunner::class,
            2,
        ];
    }
}
