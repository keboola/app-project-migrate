<?php

declare(strict_types=1);

namespace Keboola\AppProjectMigrate;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class ConfigDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $parametersNode = parent::getParametersDefinition();
        // @formatter:off
        /** @noinspection NullPointerExceptionInspection */
        $parametersNode
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
                ->enumNode('dataMode')->values(['sapi', 'database'])->defaultValue('sapi')->end()
                ->booleanNode('isSourceByodb')->defaultFalse()->end()
                ->scalarNode('sourceByodb')->end()
                ->arrayNode('includeWorkspaceSchemas')->prototype('scalar')->end()->end()
                ->booleanNode('preserveTimestamp')->defaultFalse()->end()
                ->arrayNode('componentsDevTag')
                    ->children()
                        ->scalarNode('backup')->end()
                        ->scalarNode('restore')->end()
                        ->scalarNode('tables-data')->end()
                    ->end()
                ->end()
                ->arrayNode('db')
                    ->children()
                        ->scalarNode('host')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('username')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('#password')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('warehouse')->isRequired()->cannotBeEmpty()->end()
                        ->enumNode('warehouse_size')->values(['SMALL', 'MEDIUM', 'LARGE'])->defaultValue('SMALL')->end()
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
        ;
        // @formatter:on
        return $parametersNode;
    }
}
