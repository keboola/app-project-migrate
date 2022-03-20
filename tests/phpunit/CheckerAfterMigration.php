<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests;

use Keboola\AppProjectMigrate\Checker\AfterMigration;
use Keboola\Component\UserException;
use Keboola\StorageApi\Client;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;

class CheckerAfterMigration extends TestCase
{
    public function testValidTables(): void
    {
        $destinationClient = $this->getMockDestinationClient();

        $sourceClient = $this->createMock(Client::class);
        $sourceClient
            ->method('getTable')
            ->withConsecutive(['in.bucket.table1'], ['in.bucket.table2'])
            ->willReturnOnConsecutiveCalls(['rowsCount' => 12345], ['rowsCount' => 67890]);

        $testLogger = new TestLogger();
        $afterMigrationChecker = new AfterMigration($sourceClient, $destinationClient, $testLogger);
        $afterMigrationChecker->check();
    }

    public function testInvalidTablesRowsCount(): void
    {
        $destinationClient = $this->getMockDestinationClient();

        $sourceClient = $this->createMock(Client::class);
        $sourceClient
            ->method('getTable')
            ->withConsecutive(['in.bucket.table1'], ['in.bucket.table2'])
            ->willReturnOnConsecutiveCalls(['rowsCount' => 1234567890], ['rowsCount' => 987654321]);

        $testLogger = new TestLogger();
        $afterMigrationChecker = new AfterMigration($sourceClient, $destinationClient, $testLogger);

        try {
            $afterMigrationChecker->check();
            $this->fail('Test didn\'t fail');
        } catch (UserException $e) {
            Assert::assertEquals('Failed post migration check.', $e->getMessage());
        }

        Assert::assertTrue($testLogger->hasWarning('Bad row count: Bucket "testBucket", Table "table1".'));
        Assert::assertTrue($testLogger->hasWarning('Bad row count: Bucket "testBucket", Table "table2".'));
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
