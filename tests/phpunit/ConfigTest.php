<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate\Tests;

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
            'Parameter "#sourceManageToken" is required when "migrateSecrets" is set to true.'
        );

        new Config(
            [
                'parameters' => [
                    'sourceKbcUrl' => 'https://connection.keboola.com',
                    '#sourceKbcToken' => 'token',
                    'migrateSecrets' => true,
                ],
            ],
            new ConfigDefinition()
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
            new ConfigDefinition()
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
            new ConfigDefinition()
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
            new ConfigDefinition()
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
            new ConfigDefinition()
        );

        $this->assertFalse($config->shouldMigrateTriggers());
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
            new ConfigDefinition()
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
            new ConfigDefinition()
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
            new ConfigDefinition()
        );

        $this->assertFalse($config->shouldSkipRegionValidation());
    }
}
