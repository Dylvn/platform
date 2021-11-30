<?php

namespace Oro\Bundle\EntityBundle\EntityConfig;

use Oro\Bundle\EntityConfigBundle\EntityConfig\FieldConfigInterface;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * Provides validations field config for form scope.
 */
class FormFieldConfiguration implements FieldConfigInterface
{
    public function getSectionName(): string
    {
        return 'form';
    }

    public function configure(NodeBuilder $nodeBuilder): void
    {
        $nodeBuilder
            ->arrayNode('form_options')
                ->info('`boolean` form options for a specific field. For more information, see ' .
                'Symfony Form Type Options(https://symfony.com/doc/current/forms.html#form-type-options).')
                ->prototype('variable')->end()
            ->end()
            ->booleanNode('is_enabled')
                ->info('`boolean` enables the ‘form’ functionality.')
                ->defaultTrue()
            ->end()
            ->scalarNode('form_type')
                ->info('`string` form type for a specific field.')
                ->example('Oro\Bundle\FormBundle\Form\Type\OroPercentType')
            ->end()
            ->scalarNode('type')
                ->info('same as form_type')
            ->end()
        ;
    }
}
