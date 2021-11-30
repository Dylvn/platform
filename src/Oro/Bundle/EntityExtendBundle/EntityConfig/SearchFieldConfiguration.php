<?php

namespace Oro\Bundle\EntityExtendBundle\EntityConfig;

use Oro\Bundle\EntityConfigBundle\EntityConfig\FieldConfigInterface;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * Provides validations field config for search scope.
 */
class SearchFieldConfiguration implements FieldConfigInterface
{
    public function getSectionName(): string
    {
        return 'search';
    }

    public function configure(NodeBuilder $nodeBuilder): void
    {
        $nodeBuilder
            ->booleanNode('searchable')
                ->info('`boolean` indicates what custom field could be searchable.')
            ->end()
            ->scalarNode('title_field')
                ->info('indicates what custom text field is a part of search result title.')
            ->end()
        ;
    }
}
