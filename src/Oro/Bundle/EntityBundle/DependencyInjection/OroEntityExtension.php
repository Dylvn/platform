<?php

namespace Oro\Bundle\EntityBundle\DependencyInjection;

use Oro\Component\Config\Loader\ContainerBuilderAdapter;
use Oro\Component\Config\Loader\Factory\CumulativeConfigLoaderFactory;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class OroEntityExtension extends Extension
{
    public const DEFAULT_QUERY_CACHE_LIFETIME_PARAM_NAME = 'oro_entity.default_query_cache_lifetime';
    private const CONFIG_FILE_PATH = 'Resources/config/oro/entity_hidden_fields.yml';

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->loadHiddenFieldConfigs($container);

        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('orm.yml');
        $loader->load('form_type.yml');
        $loader->load('services.yml');
        $loader->load('fallbacks.yml');
        $loader->load('services_api.yml');
        $loader->load('commands.yml');
        $loader->load('controllers.yml');
        $loader->load('controllers_api.yml');

        if ('test' === $container->getParameter('kernel.environment')) {
            $loader->load('services_test.yml');
        }

        $container->getDefinition('oro_entity.entity_name_provider.configurable')
            ->setArgument('$fields', $config['entity_name_representation']);

        $container->setParameter(
            self::DEFAULT_QUERY_CACHE_LIFETIME_PARAM_NAME,
            $config['default_query_cache_lifetime']
        );

        $loader->load('collectors.yml');
    }

    private function loadHiddenFieldConfigs(ContainerBuilder $container): void
    {
        $hiddenFieldConfigs = [];

        $configLoader = CumulativeConfigLoaderFactory::create('oro_entity_hidden_fields', self::CONFIG_FILE_PATH);
        $resources = $configLoader->load(new ContainerBuilderAdapter($container));
        foreach ($resources as $resource) {
            $hiddenFieldConfigs = array_merge(
                $hiddenFieldConfigs,
                $resource->data['oro_entity_hidden_fields']
            );
        }

        $container->setParameter('oro_entity.hidden_fields', $hiddenFieldConfigs);
    }
}
