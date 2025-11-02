<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $parametersNode = parent::getParametersDefinition();
        $parametersNode
            ->ignoreExtraKeys()
            ->children()
                ->scalarNode('sourceKbcUrl')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->defaultValue('https://connection.keboola.com')
                ->end()
                ->scalarNode('#sourceKbcToken')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->booleanNode('dryRun')->defaultFalse()->end()
                ->booleanNode('directDataMigration')->defaultTrue()->end()
                ->booleanNode('migrateConfigurations')->defaultTrue()->end()
                ->booleanNode('migratePermanentFiles')->defaultTrue()->end()
                ->booleanNode('migrateTriggers')->defaultTrue()->end()
                ->booleanNode('migrateNotifications')->defaultTrue()->end()
                ->booleanNode('migrateStructureOnly')->defaultFalse()->end()
                ->booleanNode('migrateSecrets')->defaultFalse()->end()
                ->booleanNode('migrateBuckets')->defaultTrue()->end()
                ->booleanNode('migrateTables')->defaultTrue()->end()
                ->booleanNode('migrateProjectMetadata')->defaultTrue()->end()
                ->booleanNode('skipRegionValidation')->defaultFalse()->end()
                ->booleanNode('checkEmptyProject')->defaultTrue()->end()
                ->arrayNode('tablesToMigrate')->prototype('scalar')->end()->end()
                ->arrayNode('configurationsToMigrate')->prototype('scalar')->end()->end()
                ->enumNode('dataMode')->values(['sapi', 'database'])->defaultValue('sapi')->end()
                ->booleanNode('isSourceByodb')->defaultFalse()->end()
                ->scalarNode('sourceByodb')->end()
                ->arrayNode('includeWorkspaceSchemas')->prototype('scalar')->end()->end()
                ->booleanNode('preserveTimestamp')->defaultFalse()->end()
                ->arrayNode('componentsDevTag')
                    ->children()
                        ->scalarNode('backup')->end()
                        ->scalarNode('restore')->end()
                        ->scalarNode('tablesData')->end()
                    ->end()
                ->end()
                ->arrayNode('db')
                    ->validate()->always(function ($v) {
                        if (!empty($v['#privateKey']) && !empty($v['#password'])) {
                            throw new InvalidConfigurationException(
                                'You can use either privateKey or password, not both.',
                            );
                        }
                        if (empty($v['#privateKey']) && empty($v['#password'])) {
                            throw new InvalidConfigurationException(
                                'You must provide either privateKey or password.',
                            );
                        }
                        return $v;
                    })->end()
                    ->children()
                        ->scalarNode('host')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('username')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('#password')->end()
                        ->scalarNode('#privateKey')->end()
                        ->scalarNode('warehouse')->isRequired()->cannotBeEmpty()->end()
                        ->enumNode('warehouse_size')->values(['SMALL', 'MEDIUM', 'LARGE'])->defaultValue('SMALL')->end()
                    ->end()
                ->end()
                ->arrayNode('storageBackend')
                    ->validate()->always(function ($v) {
                        /** @var array{
                         *     storageBackendType: string,
                         *     access_key_id?: string,
                         *     '#secret_access_key'?: string,
                         *     '#bucket'?: string,
                         *     region?: string,
                         *     accountName?: string,
                         *     '#accountKey'?: string,
                         *     '#jsonKey'?: string,
                         * } $v */
                        switch ($v['storageBackendType']) {
                            case Config::STORAGE_BACKEND_S3:
                                $requiredParameters = [
                                    'access_key_id',
                                    '#secret_access_key',
                                    '#bucket',
                                    'region',
                                ];
                                break;
                            case Config::STORAGE_BACKEND_ABS:
                                $requiredParameters = [
                                    'accountName',
                                    '#accountKey',
                                ];
                                break;
                            case Config::STORAGE_BACKEND_GCS:
                                $requiredParameters = [
                                    '#jsonKey',
                                    '#bucket',
                                    'region',
                                ];
                                break;
                            default:
                                throw new InvalidConfigurationException(
                                    'Invalid storageBackendType: ' . $v['storageBackendType'],
                                );
                        }
                        foreach ($requiredParameters as $param) {
                            if (empty($v[$param])) {
                                throw new InvalidConfigurationException(
                                    sprintf(
                                        'Parameter "%s" is required for storageBackendType "%s".',
                                        $param,
                                        $v['storageBackendType'],
                                    ),
                                );
                            }
                        }
                        return $v;
                    })->end()
                    ->children()
                        ->scalarNode('storageBackendType')->isRequired()->end()
                        ->scalarNode('backupPath')->isRequired()->end()
                        ->scalarNode('accountName')->end()
                        ->scalarNode('#accountKey')->end()
                        ->scalarNode('access_key_id')->end()
                        ->scalarNode('#secret_access_key')->end()
                        ->scalarNode('region')->end()
                        ->scalarNode('#bucket')->end()
                        ->scalarNode('#jsonKey')->end()
                    ->end()
                ->end()
                ->scalarNode('#sourceManageToken')->defaultNull()->end()
            ->end()
            ->validate()
                ->ifTrue(fn($values) => ($values['migrateSecrets'] ?? false) && !isset($values['#sourceManageToken']))
                ->thenInvalid('Parameter "#sourceManageToken" is required when "migrateSecrets" is set to true.')
            ->end()
            ->validate()
                ->ifTrue(fn($values) => $values['dataMode'] === 'sapi' && isset($values['db']))
                ->thenInvalid('Parameter "db" is allowed only when "dataMode" is set to "database".')
            ->end()
        ->end();
        return $parametersNode;
    }
}
