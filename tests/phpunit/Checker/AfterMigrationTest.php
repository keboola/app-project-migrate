<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests\Checker;

use Keboola\AppProjectMigrate\Checker\AfterMigration;
use Keboola\Component\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class AfterMigrationTest extends TestCase
{
    public function testValidTables(): void
    {
        $destinationClient = $this->getMockDestinationClient();

        $sourceClient = $this->createMock(Client::class);
        $callCount = 0;
        $sourceClient
            ->method('getTable')
            ->willReturnCallback(function (string $tableId) use (&$callCount): array {
                $callCount++;
                if ($tableId === 'in.bucket.table1') {
                    return ['rowsCount' => 12345];
                }
                if ($tableId === 'in.bucket.table2') {
                    return ['rowsCount' => 67890];
                }
                self::fail('Unexpected table ID: ' . $tableId);
            });

        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);
        $afterMigrationChecker = new AfterMigration($sourceClient, $destinationClient, $logger);
        $afterMigrationChecker->check();
        self::assertEquals(2, $callCount);
    }

    public function testInvalidTablesRowsCount(): void
    {
        $destinationClient = $this->getMockDestinationClient();

        $sourceClient = $this->createMock(Client::class);
        $sourceClient
            ->method('getTable')
            ->willReturnCallback(function (string $tableId): array {
                if ($tableId === 'in.bucket.table1') {
                    return ['rowsCount' => 1234567890];
                }
                if ($tableId === 'in.bucket.table2') {
                    return ['rowsCount' => 987654321];
                }
                self::fail('Unexpected table ID: ' . $tableId);
            });

        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);
        $afterMigrationChecker = new AfterMigration($sourceClient, $destinationClient, $logger);

        try {
            $afterMigrationChecker->check();
            self::fail('Test didn\'t fail');
        } catch (UserException $e) {
            self::assertEquals('Failed post migration check.', $e->getMessage());
        }

        self::assertTrue(
            $logsHandler->hasWarning(
                'Bad row count: Bucket "testBucket", Table "table1". ' .
                'Source table rows: "1234567890"; Destination table rows: "12345".',
            ),
        );
        self::assertTrue(
            $logsHandler->hasWarning(
                'Bad row count: Bucket "testBucket", Table "table2". ' .
                'Source table rows: "987654321"; Destination table rows: "67890".',
            ),
        );
    }

    public function testClientExceptionOnAllTables(): void
    {
        $destinationClient = $this->getMockDestinationClient();

        $sourceClient = $this->createMock(Client::class);
        $sourceClient
            ->method('getTable')
            ->willReturnCallback(function (string $tableId): never {
                if ($tableId === 'in.bucket.table1') {
                    throw new ClientException('Table table1 not found');
                }
                if ($tableId === 'in.bucket.table2') {
                    throw new ClientException('Table table2 not found');
                }
                self::fail('Unexpected table ID: ' . $tableId);
            });

        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);
        $afterMigrationChecker = new AfterMigration($sourceClient, $destinationClient, $logger);

        try {
            $afterMigrationChecker->check();
            self::fail('Test didn\'t fail');
        } catch (UserException $e) {
            self::assertEquals('Failed post migration check.', $e->getMessage());
        }

        self::assertTrue(
            $logsHandler->hasWarning('Table table1 not found'),
        );
        self::assertTrue(
            $logsHandler->hasWarning('Table table2 not found'),
        );
    }

    public function testClientExceptionWithValidTables(): void
    {
        $destinationClient = $this->getMockDestinationClient();

        $sourceClient = $this->createMock(Client::class);
        $sourceClient
            ->method('getTable')
            ->willReturnCallback(function (string $tableId): array {
                if ($tableId === 'in.bucket.table1') {
                    throw new ClientException('Table not found');
                }
                if ($tableId === 'in.bucket.table2') {
                    return ['rowsCount' => 67890];
                }
                self::fail('Unexpected table ID: ' . $tableId);
            });

        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);
        $afterMigrationChecker = new AfterMigration($sourceClient, $destinationClient, $logger);

        try {
            $afterMigrationChecker->check();
            self::fail('Test didn\'t fail');
        } catch (UserException $e) {
            self::assertEquals('Failed post migration check.', $e->getMessage());
        }

        self::assertTrue(
            $logsHandler->hasWarning('Table not found'),
        );
    }

    public function testMultipleBuckets(): void
    {
        $destinationClient = $this->createMock(Client::class);
        $destinationClient->expects($this->once())
            ->method('listBuckets')
            ->willReturn([
                [
                    'id' => 'in.bucket1',
                    'name' => 'testBucket1',
                ],
                [
                    'id' => 'in.bucket2',
                    'name' => 'testBucket2',
                ],
            ]);

        $destinationClient->expects($this->exactly(2))
            ->method('listTables')
            ->willReturnCallback(function (string $bucketId): array {
                if ($bucketId === 'in.bucket1') {
                    return [
                        [
                            'id' => 'in.bucket1.table1',
                            'name' => 'table1',
                            'rowsCount' => 100,
                        ],
                    ];
                }
                if ($bucketId === 'in.bucket2') {
                    return [
                        [
                            'id' => 'in.bucket2.table1',
                            'name' => 'table1',
                            'rowsCount' => 200,
                        ],
                    ];
                }
                self::fail('Unexpected bucket ID: ' . $bucketId);
            });

        $sourceClient = $this->createMock(Client::class);
        $sourceClient
            ->method('getTable')
            ->willReturnCallback(function (string $tableId): array {
                if ($tableId === 'in.bucket1.table1') {
                    return ['rowsCount' => 100];
                }
                if ($tableId === 'in.bucket2.table1') {
                    return ['rowsCount' => 200];
                }
                self::fail('Unexpected table ID: ' . $tableId);
            });

        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);
        $afterMigrationChecker = new AfterMigration($sourceClient, $destinationClient, $logger);
        $afterMigrationChecker->check();
    }

    public function testEmptyBucket(): void
    {
        $destinationClient = $this->createMock(Client::class);
        $destinationClient->expects($this->once())
            ->method('listBuckets')
            ->willReturn([
                [
                    'id' => 'in.bucket',
                    'name' => 'testBucket',
                ],
            ]);

        $destinationClient->expects($this->once())
            ->method('listTables')
            ->with('in.bucket')
            ->willReturn([]);

        $sourceClient = $this->createMock(Client::class);
        $sourceClient->expects($this->never())
            ->method('getTable');

        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);
        $afterMigrationChecker = new AfterMigration($sourceClient, $destinationClient, $logger);
        $afterMigrationChecker->check();
    }

    public function testClientExceptionAndInvalidRowsCount(): void
    {
        $destinationClient = $this->getMockDestinationClient();

        $sourceClient = $this->createMock(Client::class);
        $sourceClient
            ->method('getTable')
            ->willReturnCallback(function (string $tableId): array {
                if ($tableId === 'in.bucket.table1') {
                    throw new ClientException('Table not found');
                }
                if ($tableId === 'in.bucket.table2') {
                    return ['rowsCount' => 99999];
                }
                self::fail('Unexpected table ID: ' . $tableId);
            });

        $logsHandler = new TestHandler();
        $logger = new Logger('tests', [$logsHandler]);
        $afterMigrationChecker = new AfterMigration($sourceClient, $destinationClient, $logger);

        try {
            $afterMigrationChecker->check();
            self::fail('Test didn\'t fail');
        } catch (UserException $e) {
            self::assertEquals('Failed post migration check.', $e->getMessage());
        }

        self::assertTrue(
            $logsHandler->hasWarning('Table not found'),
        );
        self::assertTrue(
            $logsHandler->hasWarning(
                'Bad row count: Bucket "testBucket", Table "table2". ' .
                'Source table rows: "99999"; Destination table rows: "67890".',
            ),
        );
    }

    private function getMockDestinationClient(): Client
    {
        $destinationClient = $this->createMock(Client::class);
        $destinationClient->expects($this->once())
            ->method('listBuckets')
            ->willReturn([
                [
                    'id' => 'in.bucket',
                    'name' => 'testBucket',
                ],
            ]);

        $destinationClient->expects($this->once())
            ->method('listTables')->with('in.bucket')
            ->willReturn([
                [
                    'id' => 'in.bucket.table1',
                    'name' => 'table1',
                    'rowsCount' => 12345,
                ],
                [
                    'id' => 'in.bucket.table2',
                    'name' => 'table2',
                    'rowsCount' => 67890,
                ],
            ]);

        return $destinationClient;
    }
}
