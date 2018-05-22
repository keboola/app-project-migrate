<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests;

use Keboola\AppProjectMigrate\Migrate;
use Keboola\Syrup\Client as SyrupClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MigrateTest extends TestCase
{

    public function testMigrate(): void
    {
        /** @var SyrupClient|MockObject $sourceClientMock */
        $sourceClientMock = $this->createMock(SyrupClient::class);

        /** @var SyrupClient|MockObject $destClientMock */
        $destClientMock = $this->createMock(SyrupClient::class);

        // generate credentials
        $sourceClientMock->expects($this->once())
            ->method('runSyncAction')
            ->with(
                Migrate::PROJECT_BACKUP_COMPONENT,
                'generate-read-credentials',
                [
                    'parameters' => [
                        'backupId' => null,
                    ],
                ]
            )
            ->willReturn([
                'backupId' => '123',
                'backupUri' => 'https://kbc-project-migration-s3filesbucket-q91dwj6cdnoz.s3.amazonaws.com/data-takeout/us-east-1/4788/395904684/',
                'region' => 'us-east-1',
                'credentials' => [
                    'accessKeyId' => 'xxx',
                    'secretAccessKey' => 'yyy',
                    'sessionToken' => 'zzz',
                    'expiration' => '2018-05-23T10:49:02+00:00',
                ],
            ]);

        // run backup
        $sourceClientMock->expects($this->once())
            ->method('runJob')
            ->with(
                Migrate::PROJECT_BACKUP_COMPONENT,
                [
                    'configData' => [
                        'parameters' => [
                            'backupId' => '123',
                        ],
                    ],
                ]
            )
            ->willReturn([
                'id' => '222',
                'status' => 'waiting',
            ]);

        // run restore with credentials from step 1
        $destClientMock->expects($this->once())
            ->method('runJob')
            ->with(
                Migrate::PROJECT_RESTORE_COMPONENT,
                [
                    'configData' => [
                        'parameters' => [
                            'backupUri' => 'https://kbc-project-migration-s3filesbucket-q91dwj6cdnoz.s3.amazonaws.com/data-takeout/us-east-1/4788/395904684/',
                            'accessKeyId' => 'xxx',
                            '#secretAccessKey' => 'yyy',
                            '#sessionToken' => 'zzz',
                            'useDefaultBackend' => true,
                        ],
                    ],
                ]
            );


        $migrate = new Migrate($sourceClientMock, $destClientMock);
        $migrate->run();
    }
}
