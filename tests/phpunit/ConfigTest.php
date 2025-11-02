<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests;

use Generator;
use Keboola\AppProjectMigrate\Config;
use Keboola\AppProjectMigrate\ConfigDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigTest extends TestCase
{
    public function testMigrateSecretsConfigInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'Parameter "#sourceManageToken" is required when "migrateSecrets" is set to true.',
        );

        new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'migrateSecrets' => true,
                ],
            ],
            new ConfigDefinition(),
        );
    }

    public function testMigrateDataViaSapiWithDbCredentialsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Parameter "db" is allowed only when "dataMode" is set to "database".');

        new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'dataMode' => 'sapi',
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

    public function testDisabledMigrateNotifications(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'migrateNotifications' => false,
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertFalse($config->shouldMigrateNotifications());
    }

    public function testDisabledMigrateTriggers(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'migrateTriggers' => false,
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertFalse($config->shouldMigrateTriggers());
    }

    public function testDisabledMigrateConfigurations(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'migrateConfigurations' => false,
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertFalse($config->shouldMigrateConfigurations());
    }

    public function testDisabledMigratePermanentFiles(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'migratePermanentFiles' => false,
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertFalse($config->shouldMigratePermanentFiles());
    }

    public function testSkipRegionValidation(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'skipRegionValidation' => true,
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertTrue($config->shouldSkipRegionValidation());
    }

    public function testSkipRegionValidationDefaultValue(): void
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

        $this->assertFalse($config->shouldSkipRegionValidation());
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

    public function testDbPrivateKeyAndPasswordInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('You can use either privateKey or password, not both.');

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
                        '#password' => 'somePassw0rd',
                        '#privateKey' => 'SOME_PRIVATE_KEY',
                        'warehouse' => 'warehouse',
                    ],
                ],
            ],
            new ConfigDefinition(),
        );
    }

    public function testDbPrivateKeyAndPasswordMustBeSpecified(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('You must provide either privateKey or password.');

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
                        'warehouse' => 'warehouse',
                    ],
                ],
            ],
            new ConfigDefinition(),
        );
    }

    public function testConfigurationsToMigrate(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'configurationsToMigrate' => ['config1', 'config2', 'config3'],
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertEquals(['config1', 'config2', 'config3'], $config->getConfigurationsToMigrate());
    }

    public function testConfigurationsToMigrateEmpty(): void
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

        $this->assertEquals([], $config->getConfigurationsToMigrate());
    }

    public function testTablesToMigrate(): void
    {
        $config = new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'tablesToMigrate' => ['table1', 'table2', 'table3'],
                ],
            ],
            new ConfigDefinition(),
        );

        $this->assertEquals(['table1', 'table2', 'table3'], $config->getTablesToMigrate());
    }

    public function testTablesToMigrateEmpty(): void
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

        $this->assertEquals([], $config->getTablesToMigrate());
    }

    /**
     * @dataProvider invalidStorageBackendProvider
     */
    public function testStorageBackendInvalid(string $errorMessage, array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($errorMessage);

        new Config(
            [
                'parameters' => array_merge(
                    [
                        'sourceKbcUrl' => 'https://connection.keboola.com',
                        '#sourceKbcToken' => 'token',
                    ],
                    $config,
                ),
            ],
            new ConfigDefinition(),
        );
    }

    public function invalidStorageBackendProvider(): Generator
    {
        yield 'invalid type' => [
            'errorMessage' => 'Invalid storageBackendType: invalid',
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 'invalid',
                    'backupPath' => 'backup',
                ],
            ],
        ];

        yield 'S3 missing access_key_id' => [
            'errorMessage' => 'Parameter "access_key_id" is required for storageBackendType "s3".',
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 's3',
                    'backupPath' => 's3://bucket/backup',
                ],
            ],
        ];

        yield 'S3 missing secret_access_key' => [
            'errorMessage' => 'Parameter "#secret_access_key" is required for storageBackendType "s3".',
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 's3',
                    'backupPath' => 's3://bucket/backup',
                    'access_key_id' => 'key',
                ],
            ],
        ];

        yield 'S3 missing bucket' => [
            'errorMessage' => 'Parameter "#bucket" is required for storageBackendType "s3".',
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 's3',
                    'backupPath' => 's3://bucket/backup',
                    'access_key_id' => 'key',
                    '#secret_access_key' => 'secret',
                ],
            ],
        ];

        yield 'S3 missing region' => [
            'errorMessage' => 'Parameter "region" is required for storageBackendType "s3".',
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 's3',
                    'backupPath' => 's3://bucket/backup',
                    'access_key_id' => 'key',
                    '#secret_access_key' => 'secret',
                    '#bucket' => 'bucket',
                ],
            ],
        ];

        yield 'ABS missing accountName' => [
            'errorMessage' => 'Parameter "accountName" is required for storageBackendType "abs".',
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 'abs',
                    'backupPath' => 'abs://container/backup',
                ],
            ],
        ];

        yield 'ABS missing accountKey' => [
            'errorMessage' => 'Parameter "#accountKey" is required for storageBackendType "abs".',
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 'abs',
                    'backupPath' => 'abs://container/backup',
                    'accountName' => 'account',
                ],
            ],
        ];

        yield 'GCS missing jsonKey' => [
            'errorMessage' => 'Parameter "#jsonKey" is required for storageBackendType "gcs".',
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 'gcs',
                    'backupPath' => 'gcs://bucket/backup',
                ],
            ],
        ];

        yield 'GCS missing bucket' => [
            'errorMessage' => 'Parameter "#bucket" is required for storageBackendType "gcs".',
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 'gcs',
                    'backupPath' => 'gcs://bucket/backup',
                    '#jsonKey' => '{"key":"value"}',
                ],
            ],
        ];

        yield 'GCS missing region' => [
            'errorMessage' => 'Parameter "region" is required for storageBackendType "gcs".',
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 'gcs',
                    'backupPath' => 'gcs://bucket/backup',
                    '#jsonKey' => '{"key":"value"}',
                    '#bucket' => 'bucket',
                ],
            ],
        ];
    }

    /**
     * @dataProvider validStorageBackendProvider
     */
    public function testStorageBackendValid(array $config): void
    {
        self::expectNotToPerformAssertions();

        new Config(
            [
                'parameters' => array_merge(
                    [
                        'sourceKbcUrl' => 'https://connection.keboola.com',
                        '#sourceKbcToken' => 'token',
                    ],
                    $config,
                ),
            ],
            new ConfigDefinition(),
        );
    }

    public function validStorageBackendProvider(): Generator
    {
        yield 'S3 valid' => [
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 's3',
                    'backupPath' => 's3://bucket/backup',
                    'access_key_id' => 'key',
                    '#secret_access_key' => 'secret',
                    '#bucket' => 'bucket',
                    'region' => 'us-east-1',
                ],
            ],
        ];

        yield 'ABS valid' => [
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 'abs',
                    'backupPath' => 'abs://container/backup',
                    'accountName' => 'account',
                    '#accountKey' => 'key',
                ],
            ],
        ];

        yield 'GCS valid' => [
            'config' => [
                'storageBackend' => [
                    'storageBackendType' => 'gcs',
                    'backupPath' => 'gcs://bucket/backup',
                    '#jsonKey' => '{"key":"value"}',
                    '#bucket' => 'bucket',
                    'region' => 'us-central1',
                ],
            ],
        ];
    }
}
