<?php

namespace Oro\Bundle\ApiBundle\Form\DataTransformer;

use Oro\Bundle\ApiBundle\Model\EntityIdentifier;

/**
 * Transforms class name and identifier of an entity to an instance of EntityIdentifier object.
 */
class NestedAssociationTransformer extends AbstractEntityAssociationTransformer
{
    /**
     * {@inheritDoc}
     */
    protected function getEntity(string $entityClass, mixed $entityId): EntityIdentifier
    {
        $resolvedEntityClass = $this->resolveEntityClass($entityClass);
        $entity = $this->loadEntity($resolvedEntityClass, $entityId);
        $entityId = $this->doctrineHelper
            ->getEntityMetadataForClass($resolvedEntityClass)
            ->getIdentifierValues($entity);
        if (count($entityId) === 1) {
            $entityId = reset($entityId);
        }

        return new EntityIdentifier($entityId, $entityClass);
    }
}
