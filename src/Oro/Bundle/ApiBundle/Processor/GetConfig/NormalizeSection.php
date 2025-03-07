<?php

namespace Oro\Bundle\ApiBundle\Processor\GetConfig;

use Doctrine\ORM\Mapping\ClassMetadata;
use Oro\Bundle\ApiBundle\Config\EntityDefinitionConfig;
use Oro\Bundle\ApiBundle\Config\EntityDefinitionFieldConfig;
use Oro\Bundle\ApiBundle\Util\ConfigUtil;
use Oro\Bundle\ApiBundle\Util\DoctrineHelper;
use Oro\Component\ChainProcessor\ProcessorInterface;
use Oro\Component\EntitySerializer\EntityConfigInterface;
use Oro\Component\EntitySerializer\FieldConfigInterface;

/**
 * The base class for processors that do normalization of filters and sorters, such as:
 * * remove all elements marked as excluded
 * * update the property path attribute for existing elements
 * * extract elements from the definitions of related entities
 * * remove duplicated elements
 */
abstract class NormalizeSection implements ProcessorInterface
{
    /** @var DoctrineHelper */
    protected $doctrineHelper;

    public function __construct(DoctrineHelper $doctrineHelper)
    {
        $this->doctrineHelper = $doctrineHelper;
    }

    /**
     * @param EntityConfigInterface  $section
     * @param string                 $sectionName
     * @param string                 $entityClass
     * @param EntityDefinitionConfig $definition
     */
    protected function normalize(
        EntityConfigInterface $section,
        $sectionName,
        $entityClass,
        EntityDefinitionConfig $definition
    ) {
        if ($section->hasFields()) {
            $this->removeExcludedFieldsAndUpdatePropertyPath($section, $definition);
        }
        $this->collect($section, $sectionName, $entityClass, $definition);
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function removeExcludedFieldsAndUpdatePropertyPath(
        EntityConfigInterface $section,
        EntityDefinitionConfig $definition
    ) {
        $fields = $section->getFields();
        $toRemoveFieldNames = [];
        $toAddFields = [];
        foreach ($fields as $fieldName => $field) {
            if ($field->isExcluded()) {
                $toRemoveFieldNames[] = $fieldName;
            } elseif (!$field->hasPropertyPath()) {
                if ($definition->hasField($fieldName)) {
                    $propertyPath = $definition->getField($fieldName)->getPropertyPath();
                    if ($propertyPath) {
                        $field->setPropertyPath($propertyPath);
                    }
                } else {
                    $definitionFieldName = $definition->findFieldNameByPropertyPath($fieldName);
                    if ($definitionFieldName) {
                        $propertyPath = $definition->getField($definitionFieldName)->getPropertyPath();
                        if ($propertyPath) {
                            $field->setPropertyPath($propertyPath);
                            $toRemoveFieldNames[] = $fieldName;
                            $toAddFields[$definitionFieldName] = $field;
                        }
                    }
                }
            }
        }
        foreach ($toRemoveFieldNames as $fieldName) {
            $section->removeField($fieldName);
        }
        foreach ($toAddFields as $fieldName => $field) {
            $section->addField($fieldName, $field);
        }
    }

    /**
     * @param EntityConfigInterface  $section
     * @param string                 $sectionName
     * @param string                 $entityClass
     * @param EntityDefinitionConfig $definition
     */
    protected function collect(
        EntityConfigInterface $section,
        $sectionName,
        $entityClass,
        EntityDefinitionConfig $definition
    ) {
        $fields = $definition->getFields();
        foreach ($fields as $fieldName => $field) {
            if ($field->hasTargetEntity()) {
                $targetEntity = $field->getTargetEntity();
                if ($targetEntity->has($sectionName)) {
                    $this->collectNested(
                        $section,
                        $sectionName,
                        $entityClass,
                        $targetEntity,
                        $this->buildPrefix($fieldName),
                        $this->buildPrefix($field->getPropertyPath($fieldName))
                    );
                    $targetEntity->remove($sectionName);
                }
            }
        }
    }

    /**
     * @param EntityConfigInterface  $section
     * @param string                 $sectionName
     * @param string                 $entityClass
     * @param EntityDefinitionConfig $definition
     * @param string                 $fieldPrefix
     * @param string                 $pathPrefix
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function collectNested(
        EntityConfigInterface $section,
        $sectionName,
        $entityClass,
        EntityDefinitionConfig $definition,
        $fieldPrefix,
        $pathPrefix
    ) {
        /** @var FieldConfigInterface[] $sectionFields */
        $sectionFields = $definition->get($sectionName)->getFields();
        if (empty($sectionFields)) {
            return;
        }

        $path = substr($pathPrefix, 0, -1);
        $metadata = $this->getEntityMetadata($entityClass, $path);
        $isCollectionValuedAssociation = $this->isCollectionValuedAssociation(
            $metadata,
            $this->getLastFieldName($path)
        );
        foreach ($sectionFields as $fieldName => $sectionField) {
            $field = $definition->getField($fieldName);
            $propertyPath = $this->getPropertyPath($sectionField, $fieldName, $field);
            $fieldPath = $pathPrefix . $propertyPath;

            // skip identifier fields to avoid duplicates
            $targetMetadata = $this->getEntityMetadata($entityClass, $fieldPath);
            $targetFieldName = $this->getLastFieldName($fieldPath);
            if (null !== $targetMetadata
                && in_array($targetFieldName, $targetMetadata->getIdentifierFieldNames(), true)
            ) {
                continue;
            }

            if (!$isCollectionValuedAssociation
                && !$this->isCollectionValuedAssociation($targetMetadata, $targetFieldName)
            ) {
                $fieldKey = $fieldPrefix . $fieldName;
                if (!$section->hasField($fieldKey)) {
                    $section->addField($fieldKey, $sectionField);
                }
                if ($fieldPath !== $fieldKey) {
                    $sectionField->setPropertyPath($fieldPath);
                } elseif ($sectionField->hasPropertyPath()) {
                    $sectionField->setPropertyPath();
                }
            }

            if (null !== $field && $field->hasTargetEntity()) {
                $targetEntity = $field->getTargetEntity();
                if ($targetEntity->has($sectionName)) {
                    $this->collectNested(
                        $section,
                        $sectionName,
                        $entityClass,
                        $targetEntity,
                        $this->buildPrefix($fieldName, $fieldPrefix),
                        $this->buildPrefix($propertyPath, $pathPrefix)
                    );
                    $targetEntity->remove($sectionName);
                }
            }
        }
    }

    /**
     * @param FieldConfigInterface             $sectionField
     * @param string                           $fieldName
     * @param EntityDefinitionFieldConfig|null $field
     *
     * @return string
     */
    protected function getPropertyPath(
        FieldConfigInterface $sectionField,
        $fieldName,
        EntityDefinitionFieldConfig $field = null
    ) {
        $propertyPath = $fieldName;
        if ($sectionField->hasPropertyPath()) {
            $propertyPath = $sectionField->getPropertyPath();
        } elseif (null !== $field && $field->hasPropertyPath()) {
            $propertyPath = $field->getPropertyPath();
        }

        return $propertyPath;
    }

    /**
     * @param string      $name
     * @param string|null $currentPrefix
     *
     * @return string
     */
    protected function buildPrefix($name, $currentPrefix = null)
    {
        return (null !== $currentPrefix ? $currentPrefix . $name : $name) . ConfigUtil::PATH_DELIMITER;
    }

    /**
     * @param ClassMetadata|null $metadata
     * @param string             $fieldName
     *
     * @return bool
     */
    protected function isCollectionValuedAssociation($metadata, $fieldName)
    {
        return
            null !== $metadata
            && $metadata->hasAssociation($fieldName)
            && $metadata->isCollectionValuedAssociation($fieldName);
    }

    /**
     * @param string $entityClass
     * @param string $propertyPath
     *
     * @return ClassMetadata|null
     */
    protected function getEntityMetadata($entityClass, $propertyPath)
    {
        $metadata = null;
        if ($this->doctrineHelper->isManageableEntityClass($entityClass)) {
            $path = ConfigUtil::explodePropertyPath($propertyPath);
            array_pop($path);
            $metadata = !empty($path)
                ? $this->doctrineHelper->findEntityMetadataByPath($entityClass, $path)
                : $this->doctrineHelper->getEntityMetadataForClass($entityClass);
        }

        return $metadata;
    }

    /**
     * @param string $propertyPath
     *
     * @return string
     */
    protected function getLastFieldName($propertyPath)
    {
        $path = ConfigUtil::explodePropertyPath($propertyPath);

        return array_pop($path);
    }
}
