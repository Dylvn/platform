<?php

namespace Oro\Bundle\DataAuditBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Oro\Bundle\DataAuditBundle\Async\Topics;
use Oro\Bundle\DataAuditBundle\DependencyInjection\CompilerPass\EntityAuditStrategyPass;
use Oro\Bundle\MessageQueueBundle\DependencyInjection\Compiler\AddTopicDescriptionPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The OroDataAuditBundle bundle class.
 */
class OroDataAuditBundle extends Bundle
{
    /**
     * {@inheritDoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(
            AddTopicDescriptionPass::create()
                ->add(Topics::ENTITIES_CHANGED, 'Creates audit for usual entity properties')
                ->add(Topics::ENTITIES_RELATIONS_CHANGED, '[Internal] Creates audit for entity rel.')
                ->add(Topics::ENTITIES_INVERSED_RELATIONS_CHANGED, '[Internal] Create audit for entity inverse rel.')
        );

        if ('test' === $container->getParameter('kernel.environment')) {
            $container->addCompilerPass(
                DoctrineOrmMappingsPass::createAnnotationMappingDriver(
                    ['Oro\Bundle\DataAuditBundle\Tests\Functional\Environment\Entity'],
                    [$this->getPath() . '/Tests/Functional/Environment/Entity']
                )
            );
        }

        $container->addCompilerPass(new EntityAuditStrategyPass());
    }
}
