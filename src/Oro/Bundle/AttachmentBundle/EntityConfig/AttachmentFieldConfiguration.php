<?php

namespace Oro\Bundle\AttachmentBundle\EntityConfig;

use Oro\Bundle\EntityConfigBundle\EntityConfig\FieldConfigInterface;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * Provides validations field config for attachment scope.
 */
class AttachmentFieldConfiguration implements FieldConfigInterface
{
    public function getSectionName(): string
    {
        return 'attachment';
    }

    public function configure(NodeBuilder $nodeBuilder): void
    {
        $nodeBuilder
            ->arrayNode('attachment')
                ->prototype('variable')->end()
            ->end()
            ->scalarNode('maxsize')
                ->info('`integer` sets the max size of an uploaded file in megabytes.')
            ->end()
            ->scalarNode('width')
                ->info('`integer` sets width for a picture thumbnail in pixels.')
            ->end()
            ->scalarNode('height')
                ->info('`integer` sets height for a picture thumbnail in pixels.')
            ->end()
            ->scalarNode('mimetypes')
                ->info('`string` the list of all allowed MIME types. MIME types are delimited by linefeed (n) ' .
                'symbol. Example of values: ‘image/jpeg’, ‘image/gif’, ‘application/pdf’.')
            ->end()
            ->scalarNode('max_number_of_files')
                ->info('`integer` sets the max number of files.')
            ->end()
            ->arrayNode('file_applications')
                ->prototype('variable')->end()
                ->defaultValue(['default'])
            ->end()
        ;
    }
}
