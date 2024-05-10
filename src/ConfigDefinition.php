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
                ->booleanNode('directDataMigration')->defaultTrue()->end()
                ->booleanNode('migrateSecrets')->defaultFalse()->end()
                ->scalarNode('#sourceManageToken')->defaultNull()->end()
            ->end()
            ->validate()
                ->ifTrue(fn($values) => ($values['migrateSecrets'] ?? false) && !isset($values['#sourceManageToken']))
                ->thenInvalid('Parameter "#sourceManageToken" is required when "migrateSecrets" is set to true.')
            ->end()
        ;
        // @formatter:on
        return $parametersNode;
    }
}
