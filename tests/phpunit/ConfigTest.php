<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests;

use Generator;
use Keboola\AppProjectMigrate\Config;
use Keboola\AppProjectMigrate\ConfigDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use TypeError;

class ConfigTest extends TestCase
{
    /**
     * @dataProvider invalidConfigurationProvider
     * @param array<string, mixed> $parameters
     */
    public function testInvalidConfiguration(
        array $parameters,
        string $expectedExceptionMessage,
    ): void {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        new Config(
            [
                'parameters' => array_merge(
                    [
                        'sourceKbcUrl' => 'https://connection.keboola.com',
                        '#sourceKbcToken' => 'token',
                    ],
                    $parameters,
                ),
            ],
            new ConfigDefinition(),
        );
    }

    /**
     * @return Generator<string, array{array<string, mixed>, string}>
     */
    public function invalidConfigurationProvider(): Generator
    {
        yield 'migrateSecrets without sourceManageToken' => [
            ['migrateSecrets' => true],
            'Parameter "#sourceManageToken" is required when "migrateSecrets" is set to true.',
        ];

        yield 'db credentials with sapi mode' => [
            [
                'dataMode' => 'sapi',
                'db' => [
                    'host' => 'host',
                    'username' => 'username',
                    '#password' => 'password',
                    'warehouse' => 'warehouse',
                ],
            ],
            'Parameter "db" is allowed only when "dataMode" is set to "database".',
        ];
    }

    public function testMigrateSecretsConfigValid(): void
    {
        $baseConfig = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'migrateSecrets' => true,
                    '#sourceManageToken' => 'manage-token',
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertSame(true, $baseConfig->shouldMigrateSecrets());
        $this->assertEquals('manage-token', $baseConfig->getSourceManageToken());
    }

    /**
     * @dataProvider booleanParameterProvider
     */
    public function testBooleanParameters(
        string $parameterName,
        string $methodName,
        bool $testValue,
        bool $defaultValue,
    ): void {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    $parameterName => $testValue,
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertSame($testValue, $config->$methodName());
    }

    /**
     * @dataProvider booleanParameterDefaultProvider
     */
    public function testBooleanParametersDefaultValue(
        string $methodName,
        bool $expectedDefault,
    ): void {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertSame($expectedDefault, $config->$methodName());
    }

    /**
     * @return Generator<string, array{string, string, bool, bool}>
     */
    public function booleanParameterProvider(): Generator
    {
        yield 'migrateNotifications false' => ['migrateNotifications', 'shouldMigrateNotifications', false, true];
        yield 'migrateTriggers false' => ['migrateTriggers', 'shouldMigrateTriggers', false, true];
        yield 'migratePermanentFiles false' => ['migratePermanentFiles', 'shouldMigratePermanentFiles', false, true];
        yield 'skipRegionValidation true' => ['skipRegionValidation', 'shouldSkipRegionValidation', true, false];
        yield 'dryRun true' => ['dryRun', 'isDryRun', true, false];
        yield 'directDataMigration false' => ['directDataMigration', 'directDataMigration', false, true];
        yield 'migrateStructureOnly true' => ['migrateStructureOnly', 'shouldMigrateStructureOnly', true, false];
        yield 'migrateBuckets false' => ['migrateBuckets', 'shouldMigrateBuckets', false, true];
        yield 'migrateTables false' => ['migrateTables', 'shouldMigrateTables', false, true];
        yield 'migrateProjectMetadata false' => ['migrateProjectMetadata', 'shouldMigrateProjectMetadata', false, true];
        yield 'isSourceByodb true' => ['isSourceByodb', 'isSourceByodb', true, false];
        yield 'checkEmptyProject false' => ['checkEmptyProject', 'checkEmptyProject', false, true];
        yield 'preserveTimestamp true' => ['preserveTimestamp', 'preserveTimestamp', true, false];
    }

    /**
     * @return Generator<string, array{string, bool}>
     */
    public function booleanParameterDefaultProvider(): Generator
    {
        yield 'migrateNotifications' => ['shouldMigrateNotifications', true];
        yield 'migrateTriggers' => ['shouldMigrateTriggers', true];
        yield 'migratePermanentFiles' => ['shouldMigratePermanentFiles', true];
        yield 'skipRegionValidation' => ['shouldSkipRegionValidation', false];
        yield 'dryRun' => ['isDryRun', false];
        yield 'directDataMigration' => ['directDataMigration', true];
        yield 'migrateStructureOnly' => ['shouldMigrateStructureOnly', false];
        yield 'migrateBuckets' => ['shouldMigrateBuckets', true];
        yield 'migrateTables' => ['shouldMigrateTables', true];
        yield 'migrateProjectMetadata' => ['shouldMigrateProjectMetadata', true];
        yield 'isSourceByodb' => ['isSourceByodb', false];
        yield 'checkEmptyProject' => ['checkEmptyProject', true];
        yield 'preserveTimestamp' => ['preserveTimestamp', false];
        yield 'migrateSecrets' => ['shouldMigrateSecrets', false];
    }

    public function testAdditionalMigrationParametersSapiMode(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'dataMode' => 'sapi',
                    'preserveTimestamp' => true,
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertTrue($config->preserveTimestamp());
    }

    public function testAdditionalMigrationParametersDbMode(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'dataMode' => 'database',
                    'isSourceByodb' => true,
                    'sourceByodb'=> 'test',
                    'includeWorkspaceSchemas' => ['workspace1', 'workspace2'],
                    'db' => [
                        'host' => 'host',
                        'username' => 'username',
                        '#password' => 'password',
                        'warehouse' => 'warehouse',
                    ],
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertEquals(2, count($config->getIncludeWorkspaceSchemas()));
        $this->assertEquals('test', $config->getSourceByodb());
    }

    public function testDbWithPrivateKey(): void
    {
        self::expectNotToPerformAssertions();

        new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'dataMode' => 'database',
                    'isSourceByodb' => true,
                    'sourceByodb'=> 'test',
                    'includeWorkspaceSchemas' => ['workspace1', 'workspace2'],
                    'db' => [
                        'host' => 'host',
                        'username' => 'username',
                        '#privateKey' => 'SOME_PRIVATE_KEY',
                        'warehouse' => 'warehouse',
                    ],
                ],
            ],
            new ConfigDefinition(),
        );
    }

    /**
     * @dataProvider dbValidationProvider
     * @param array{
     *     host: string,
     *     username: string,
     *     '#password'?: string,
     *     '#privateKey'?: string,
     *     warehouse: string,
     *     warehouse_size?: 'SMALL'|'MEDIUM'|'LARGE'
     * } $dbConfig
     */
    public function testDbValidation(
        array $dbConfig,
        string $expectedExceptionMessage,
    ): void {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'dataMode' => 'database',
                    'isSourceByodb' => true,
                    'sourceByodb'=> 'test',
                    'includeWorkspaceSchemas' => ['workspace1', 'workspace2'],
                    'db' => $dbConfig,
                ],
            ],
            new ConfigDefinition(),
        );
    }

    /**
     * @return Generator<string, array{array<string, mixed>, string}>
     */
    public function dbValidationProvider(): Generator
    {
        yield 'both password and privateKey' => [
            [
                'host' => 'host',
                'username' => 'username',
                '#password' => 'somePassw0rd',
                '#privateKey' => 'SOME_PRIVATE_KEY',
                'warehouse' => 'warehouse',
            ],
            'You can use either privateKey or password, not both.',
        ];

        yield 'neither password nor privateKey' => [
            [
                'host' => 'host',
                'username' => 'username',
                'warehouse' => 'warehouse',
            ],
            'You must provide either privateKey or password.',
        ];
    }

    public function testGetSourceProjectUrl(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://custom.keboola.com',
                    '#sourceKbcToken' => 'token',
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertEquals('https://custom.keboola.com', $config->getSourceProjectUrl());
    }

    public function testGetSourceProjectToken(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'my-token-123',
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertEquals('my-token-123', $config->getSourceProjectToken());
    }


    /**
     * @dataProvider migrateDataModeProvider
     * @param array<string, mixed> $parameters
     */
    public function testGetMigrateDataMode(
        array $parameters,
        string $expectedValue,
    ): void {
        $config = new Config(
            [
                'parameters' => array_merge(
                    [
                        'sourceKbcUrl' => 'https://connection.keboola.com',
                        '#sourceKbcToken' => 'token',
                    ],
                    $parameters,
                ),
            ],
            new ConfigDefinition(),
        );

        $this->assertEquals($expectedValue, $config->getMigrateDataMode());
    }

    /**
     * @return Generator<string, array{array<string, mixed>, string}>
     */
    public function migrateDataModeProvider(): Generator
    {
        yield 'database mode' => [['dataMode' => 'database'], 'database'];
        yield 'default sapi mode' => [[], 'sapi'];
    }

    /**
     * @dataProvider dbConfigProvider
     * @param array<string, mixed> $parameters
     * @param array{
     *     host: string,
     *     username: string,
     *     '#password'?: string,
     *     '#privateKey'?: string,
     *     warehouse: string,
     *     warehouse_size?: 'SMALL'|'MEDIUM'|'LARGE'
     * }|array{} $expectedValue
     */
    public function testGetDb(
        array $parameters,
        array $expectedValue,
    ): void {
        $config = new Config(
            [
                'parameters' => array_merge(
                    [
                        'sourceKbcUrl' => 'https://connection.keboola.com',
                        '#sourceKbcToken' => 'token',
                    ],
                    $parameters,
                ),
            ],
            new ConfigDefinition(),
        );

        $this->assertEquals($expectedValue, $config->getDb());
    }

    /**
     * @return Generator<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public function dbConfigProvider(): Generator
    {
        $dbConfig = [
            'host' => 'host',
            'username' => 'username',
            '#password' => 'password',
            'warehouse' => 'warehouse',
            'warehouse_size' => 'SMALL',
        ];

        yield 'db config with database mode' => [
            [
                'dataMode' => 'database',
                'db' => $dbConfig,
            ],
            $dbConfig,
        ];

        yield 'default empty db config' => [[], []];
    }


    /**
     * @dataProvider appTagProvider
     */
    public function testAppTags(string $tagKey, string $methodName, string $testValue): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'componentsDevTag' => [
                        $tagKey => $testValue,
                    ],
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertEquals($testValue, $config->$methodName());
    }

    /**
     * @dataProvider appTagDefaultProvider
     */
    public function testAppTagsDefaultValue(string $methodName): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertNull($config->$methodName());
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public function appTagProvider(): Generator
    {
        yield 'backup tag' => ['backup', 'getAppBackupTag', 'dev-tag-backup'];
        yield 'restore tag' => ['restore', 'getAppRestoreTag', 'dev-tag-restore'];
        yield 'tablesData tag' => ['tablesData', 'getAppTablesDataTag', 'dev-tag-tables'];
    }

    /**
     * @return Generator<string, array{string}>
     */
    public function appTagDefaultProvider(): Generator
    {
        yield 'backup tag default' => ['getAppBackupTag'];
        yield 'restore tag default' => ['getAppRestoreTag'];
        yield 'tablesData tag default' => ['getAppTablesDataTag'];
    }

    public function testGetIncludeWorkspaceSchemasEmpty(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertEquals([], $config->getIncludeWorkspaceSchemas());
    }

    public function testGetSourceByodbDefaultValue(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertEquals('', $config->getSourceByodb());
    }

    public function testGetSourceManageTokenDefaultValue(): void
    {
        $this->expectException(TypeError::class);

        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                ],
            ],
            new ConfigDefinition(),
        );

        // getSourceManageToken catches InvalidArgumentException, but getStringValue throws TypeError
        // when value is null (from defaultNull()). This is a known limitation.
        $config->getSourceManageToken();
    }
}
