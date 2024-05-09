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
}
