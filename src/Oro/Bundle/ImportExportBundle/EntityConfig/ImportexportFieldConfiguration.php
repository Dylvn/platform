<?php

namespace Oro\Bundle\ImportExportBundle\EntityConfig;

use Oro\Bundle\EntityConfigBundle\EntityConfig\FieldConfigInterface;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * Provides validations field config for importexport scope.
 */
class ImportexportFieldConfiguration implements FieldConfigInterface
{
    public function getSectionName(): string
    {
        return 'importexport';
    }

    public function configure(NodeBuilder $nodeBuilder): void
    {
        $nodeBuilder
            ->scalarNode('header')
                ->info('`string` sets a custom field header. By default, field label is used.')
            ->end()
            ->scalarNode('order')
                ->info('`integer` used to configure a custom column order.')
            ->end()
            ->scalarNode('identity')
                ->info('`boolean` fields with this option are used to identify (search) the entity. You can use ' .
                'multiple identity fields for one entity.')
            ->end()
            ->booleanNode('excluded')
                ->info('`boolean` fields with this option cannot be exported.')
            ->end()
            ->booleanNode('full')
                ->info('`boolean` all related entity fields’ are exported. Fields with the excluded option are ' .
                'skipped. If the option is set to false (the default value), only fields with an ' .
                'identity are exported.')
            ->end()
            ->booleanNode('process_as_scalar')
                ->info('`boolean` defines whether a relation field is processed as scalar value when exporting data.')
                ->defaultFalse()
            ->end()
            ->booleanNode('immutable')
            ->end()
            ->scalarNode('fallback_field')->end()
            ->arrayNode('short')
                ->prototype('variable')->end()
            ->end()
        ;
    }
}
