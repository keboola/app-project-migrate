<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests;

use Generator;
use Keboola\AppProjectMigrate\Config;
use Keboola\AppProjectMigrate\DockerRunnerClient;
use Keboola\AppProjectMigrate\Migrate;
use Keboola\Component\UserException;
use Keboola\Syrup\ClientException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MigrateTest extends TestCase
{

    /**
     * @dataProvider successMigrateDataProvider
     */
    public function testMigrateSuccess(array $expectedCredentialsData): void
    {
        $sourceClientMock = $this->createMock(DockerRunnerClient::class);
        $destClientMock = $this->createMock(DockerRunnerClient::class);

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
        $destClientMock->expects($this->exactly(3))
            ->method('runJob')
            ->withConsecutive(
                // restore data
                [
                    Config::PROJECT_RESTORE_COMPONENT,
                    [
                        'configData' => [
                            'parameters' => array_merge($expectedCredentialsData, ['useDefaultBackend' => true]),
                        ],
                    ],
                ],
                // restore snowflake writers
                [
                    Config::SNOWFLAKE_WRITER_MIGRATE_COMPONENT,
                    [
                        'configData' => [
                            'parameters' => [
                                'sourceKbcUrl' => $sourceProjectUrl,
                                '#sourceKbcToken' => $sourceProjectToken,
                            ],
                        ],
                    ],
                ],
                // restore orchestrations
                [
                    Config::ORCHESTRATOR_MIGRATE_COMPONENT,
                    [
                        'configData' => [
                            'parameters' => [
                                'sourceKbcUrl' => $sourceProjectUrl,
                                '#sourceKbcToken' => $sourceProjectToken,
                            ],
                        ],
                    ],
                ]
            )->willReturn([
                'id' => '222',
                'status' => 'success',
            ]);

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
        $sourceClientMock = $this->createMock(DockerRunnerClient::class);
        $destClientMock = $this->createMock(DockerRunnerClient::class);

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
        $sourceClientMock = $this->createMock(DockerRunnerClient::class);
        $destClientMock = $this->createMock(DockerRunnerClient::class);

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
        $sourceClientMock = $this->createMock(DockerRunnerClient::class);
        $destinationClientMock = $this->createMock(DockerRunnerClient::class);

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
                    'configData' => [
                        'parameters' => [
                            'backupId' => '123',
                        ],
                    ],
                ]
            )
            ->willReturn($return)
        ;
    }

    public function successMigrateDataProvider(): Generator
    {
        yield 'migrate S3' => [
            [
                's3' => [
                    'backupUri' => 'https://kbc.s3.amazonaws.com/data-takeout/us-east-1/4788/395904684/',
                    'accessKeyId' => 'xxx',
                    '#secretAccessKey' => 'yyy',
                    '#sessionToken' => 'zzz',
                ],
            ],
        ];

        yield 'migrate ABS' => [
            [
                'abs' => [
                    'container' => 'abcdefgh',
                    '#connectionString' => 'https://testConnectionString',
                ],
            ],
        ];
    }
}
