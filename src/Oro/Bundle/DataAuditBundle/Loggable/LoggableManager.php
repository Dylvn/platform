<?php

namespace Oro\Bundle\DataAuditBundle\Loggable;

use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Security\Core\SecurityContextInterface;

use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\Common\Util\ClassUtils;

use Oro\Bundle\DataAuditBundle\Entity\Audit;
use Oro\Bundle\DataAuditBundle\Metadata\ClassMetadata;
use Oro\Bundle\DataAuditBundle\Metadata\PropertyMetadata;

use Oro\Bundle\UserBundle\Entity\User;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\EntityConfigBundle\DependencyInjection\Utils\ServiceLink;
use Oro\Bundle\SecurityBundle\Authentication\Token\OrganizationContextTokenInterface;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * TODO: This class should be refactored  (BAP-978)
 */
class LoggableManager
{
    protected static $userCache = array();

    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_REMOVE = 'remove';

    /** @var EntityManager */
    protected $em;

    /** @var array */
    protected $configs = array();

    /** @var string */
    protected $username;

    /** @var Organization|null */
    protected $organization = null;

    /** @var string */
    protected $logEntityClass;

    /** @var array */
    protected $pendingLogEntityInserts = array();

    /** @var array */
    protected $pendingRelatedEntities = array();

    /** @var array */
    protected $collectionLogData = array();

    /** @var ConfigProvider */
    protected $auditConfigProvider;

    /** @var ServiceLink  */
    protected $securityContextLink;

    /**
     * @param string $logEntityClass
     * @param ConfigProvider $auditConfigProvider
     * @param ServiceLink $securityContextLink
     */
    public function __construct(
        $logEntityClass,
        ConfigProvider $auditConfigProvider,
        ServiceLink $securityContextLink
    ) {
        $this->auditConfigProvider = $auditConfigProvider;
        $this->logEntityClass      = $logEntityClass;
        $this->securityContextLink = $securityContextLink;
    }

    /**
     * @param ClassMetadata $metadata
     */
    public function addConfig(ClassMetadata $metadata)
    {
        $this->configs[$metadata->name] = $metadata;
    }

    /**
     * @param $name
     * @return ClassMetadata
     * @throws InvalidParameterException
     */
    public function getConfig($name)
    {
        if (!isset($this->configs[$name])) {
            throw new InvalidParameterException(sprintf('invalid config name %s', $name));
        }

        return $this->configs[$name];
    }

    /**
     * @param $username
     * @throws \InvalidArgumentException
     */
    public function setUsername($username)
    {
        if (is_string($username)) {
            $this->username = $username;
        } elseif (is_object($username) && method_exists($username, 'getUsername')) {
            $this->username = (string) $username->getUsername();
        } else {
            throw new \InvalidArgumentException("Username must be a string, or object should have method: getUsername");
        }
    }

    /**
     * @return null|Organization
     */
    protected function getOrganization()
    {
        /** @var SecurityContextInterface $securityContext */
        $securityContext = $this->securityContextLink->getService();

        $token = $securityContext->getToken();
        if (!$token) {
            return null;
        }

        if (!$token instanceof OrganizationContextTokenInterface) {
            return null;
        }

        return $token->getOrganizationContext();
    }

    /**
     * @param EntityManager $em
     */
    public function handleLoggable(EntityManager $em)
    {
        $this->em = $em;
        $uow      = $em->getUnitOfWork();

        $collections = array_merge($uow->getScheduledCollectionUpdates(), $uow->getScheduledCollectionDeletions());
        foreach ($collections as $collection) {
            $this->calculateCollectionData($collection);
        }

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->createLogEntity(self::ACTION_CREATE, $entity);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->createLogEntity(self::ACTION_UPDATE, $entity);
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->createLogEntity(self::ACTION_REMOVE, $entity);
        }
    }

    /**
     * @param               $entity
     * @param EntityManager $em
     */
    public function handlePostPersist($entity, EntityManager $em)
    {
        $this->em = $em;
        $uow      = $em->getUnitOfWork();
        $oid      = spl_object_hash($entity);

        if ($this->pendingLogEntityInserts && array_key_exists($oid, $this->pendingLogEntityInserts)) {
            $logEntry     = $this->pendingLogEntityInserts[$oid];
            $logEntryMeta = $em->getClassMetadata(ClassUtils::getClass($logEntry));

            $id = $this->getIdentifier($entity);
            $logEntryMeta->getReflectionProperty('objectId')->setValue($logEntry, $id);

            $uow->scheduleExtraUpdate(
                $logEntry,
                array(
                    'objectId' => array(null, $id)
                )
            );
            $uow->setOriginalEntityProperty(spl_object_hash($logEntry), 'objectId', $id);

            unset($this->pendingLogEntityInserts[$oid]);
        }

        if ($this->pendingRelatedEntities && array_key_exists($oid, $this->pendingRelatedEntities)) {
            $identifiers = $uow->getEntityIdentifier($entity);

            foreach ($this->pendingRelatedEntities[$oid] as $props) {
                /** @var Audit $logEntry */
                $logEntry = $props['log'];
                $oldData  = $data = $logEntry->getData();
                if (empty($data[$props['field']]['new'])) {
                    $data[$props['field']]['new'] = $identifiers;
                    $logEntry->setData($data);

                    $uow->scheduleExtraUpdate(
                        $logEntry,
                        array(
                            'data' => array($oldData, $data)
                        )
                    );
                    $uow->setOriginalEntityProperty(spl_object_hash($logEntry), 'objectId', $data);
                }
            }

            unset($this->pendingRelatedEntities[$oid]);
        }
    }

    /**
     * @param PersistentCollection $collection
     */
    protected function calculateCollectionData(PersistentCollection $collection)
    {
        $ownerEntity          = $collection->getOwner();
        $ownerEntityClassName = $this->getEntityClassName($ownerEntity);

        if ($this->checkAuditable($ownerEntityClassName)) {
            $meta              = $this->getConfig($ownerEntityClassName);
            $collectionMapping = $collection->getMapping();

            if (isset($meta->propertyMetadata[$collectionMapping['fieldName']])) {
                $method = $meta->propertyMetadata[$collectionMapping['fieldName']]->method;

                // calculate collection changes
                $newCollection = $collection->toArray();
                $oldCollection = $collection->getSnapshot();

                $oldData = array_reduce(
                    $oldCollection,
                    function ($result, $item) use ($method) {
                        return $result . ($result ? ', ' : '') . $item->{$method}();
                    }
                );

                $newData = array_reduce(
                    $newCollection,
                    function ($result, $item) use ($method) {
                        return $result . ($result ? ', ' : '') . $item->{$method}();
                    }
                );

                $entityIdentifier = $this->getEntityIdentifierString($ownerEntity);
                $fieldName = $collectionMapping['fieldName'];
                $this->collectionLogData[$ownerEntityClassName][$entityIdentifier][$fieldName] = array(
                    'old' => $oldData,
                    'new' => $newData,
                );
            }
        }
    }

    /**
     * @param string $action
     * @param mixed  $entity
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @throws \ReflectionException
     */
    protected function createLogEntity($action, $entity)
    {
        if (!$this->username) {
            return;
        }

        $entityClassName = $this->getEntityClassName($entity);
        if (!$this->checkAuditable($entityClassName)) {
            return;
        }

        $user = $this->getLoadedUser();
        if (!$user) {
            return;
        }

        $uow = $this->em->getUnitOfWork();

        $meta       = $this->getConfig($entityClassName);
        $entityMeta = $this->em->getClassMetadata($entityClassName);

        $logEntryMeta = $this->em->getClassMetadata($this->getLogEntityClass());
        /** @var Audit $logEntry */
        $logEntry = $logEntryMeta->newInstance();
        $logEntry->setAction($action);
        $logEntry->setObjectClass($meta->name);
        $logEntry->setLoggedAt();
        $logEntry->setUser($user);
        $logEntry->setOrganization($this->getOrganization());
        $logEntry->setObjectName(method_exists($entity, '__toString') ? $entity->__toString() : $meta->name);

        $entityId = $this->getIdentifier($entity);

        if (!$entityId && $action === self::ACTION_CREATE) {
            $this->pendingLogEntityInserts[spl_object_hash($entity)] = $logEntry;
        }

        $logEntry->setObjectId($entityId);

        $newValues = array();

        if ($action !== self::ACTION_REMOVE && count($meta->propertyMetadata)) {
            foreach ($uow->getEntityChangeSet($entity) as $field => $changes) {

                if (!isset($meta->propertyMetadata[$field])) {
                    continue;
                }

                $old = $changes[0];
                $new = $changes[1];

                if ($old == $new) {
                    continue;
                }

                $fieldMapping = null;
                if ($entityMeta->hasField($field)) {
                    $fieldMapping = $entityMeta->getFieldMapping($field);
                    if ($fieldMapping['type'] == 'date') {
                        // leave only date
                        $utc = new \DateTimeZone('UTC');
                        if ($old && $old instanceof \DateTime) {
                            $old->setTimezone($utc);
                            $old = new \DateTime($old->format('Y-m-d'), $utc);
                        }
                        if ($new && $new instanceof \DateTime) {
                            $new->setTimezone($utc);
                            $new = new \DateTime($new->format('Y-m-d'), $utc);
                        }
                    }
                }

                if ($old instanceof \DateTime && $new instanceof \DateTime
                    && $old->getTimestamp() == $new->getTimestamp()
                ) {
                    continue;
                }

                // need to distinguish date and datetime
                if ($fieldMapping && in_array($fieldMapping['type'], array('date', 'datetime'))) {
                    if ($old instanceof \DateTime) {
                        $old = array('type' => $fieldMapping['type'], 'value' => $old);
                    }
                    if ($new instanceof \DateTime) {
                        $new = array('type' => $fieldMapping['type'], 'value' => $new);
                    }
                }

                if ($entityMeta->isSingleValuedAssociation($field) && $new) {
                    $oid   = spl_object_hash($new);
                    $value = $this->getIdentifier($new);

                    if (!is_array($value) && !$value) {
                        $this->pendingRelatedEntities[$oid][] = array(
                            'log'   => $logEntry,
                            'field' => $field
                        );
                    }

                    $method = $meta->propertyMetadata[$field]->method;
                    if ($old !== null) {
                        // check if an object has the required method to avoid a fatal error
                        if (!method_exists($old, $method)) {
                            throw new \ReflectionException(
                                sprintf('Try to call to undefined method %s::%s', get_class($old), $method)
                            );
                        }
                        $old = $old->{$method}();
                    }
                    if ($new !== null) {
                        // check if an object has the required method to avoid a fatal error
                        if (!method_exists($new, $method)) {
                            throw new \ReflectionException(
                                sprintf('Try to call to undefined method %s::%s', get_class($new), $method)
                            );
                        }
                        $new = $new->{$method}();
                    }
                }

                $newValues[$field] = array(
                    'old' => $old,
                    'new' => $new,
                );
            }

            $entityIdentifier = $this->getEntityIdentifierString($entity);
            if (!empty($this->collectionLogData[$entityClassName][$entityIdentifier])) {
                $collectionData = $this->collectionLogData[$entityClassName][$entityIdentifier];
                foreach ($collectionData as $field => $changes) {
                    if (!isset($meta->propertyMetadata[$field])) {
                        continue;
                    }

                    if ($changes['old'] != $changes['new']) {
                        $newValues[$field] = $changes;
                    }
                }
            }

            $logEntry->setData($newValues);
        }

        if ($action === self::ACTION_UPDATE && 0 === count($newValues)) {
            return;
        }

        $version = 1;

        if ($action !== self::ACTION_CREATE) {
            $version = $this->getNewVersion($logEntryMeta, $entity);

            if (empty($version)) {
                // was versioned later
                $version = 1;
            }
        }

        $logEntry->setVersion($version);

        $this->em->persist($logEntry);
        $uow->computeChangeSet($logEntryMeta, $logEntry);
    }

    /**
     * @return User
     */
    protected function getLoadedUser()
    {
        $isInCache = array_key_exists($this->username, self::$userCache);
        if (!$isInCache
            || ($isInCache && !$this->em->getUnitOfWork()->isInIdentityMap(self::$userCache[$this->username]))
        ) {
            $this->loadUser();
        }

        return self::$userCache[$this->username];
    }

    protected function loadUser()
    {
        self::$userCache[$this->username] = $this->em
            ->getRepository('OroUserBundle:User')
            ->findOneBy(array('username' => $this->username));
    }

    /**
     * Get the LogEntry class
     *
     * @return string
     */
    protected function getLogEntityClass()
    {
        return $this->logEntityClass;
    }

    /**
     * @param $logEntityMeta
     * @param $entity
     * @return mixed
     */
    protected function getNewVersion($logEntityMeta, $entity)
    {
        $entityMeta = $this->em->getClassMetadata($this->getEntityClassName($entity));
        $entityId   = $this->getIdentifier($entity);

        $dql = "SELECT MAX(log.version) FROM {$logEntityMeta->name} log";
        $dql .= " WHERE log.objectId = :objectId";
        $dql .= " AND log.objectClass = :objectClass";

        $q = $this->em->createQuery($dql);
        $q->setParameters(
            array(
                'objectId'    => $entityId,
                'objectClass' => $entityMeta->name
            )
        );

        return $q->getSingleScalarResult() + 1;
    }

    /**
     * @param       $entity
     * @param  null $entityMeta
     * @return mixed
     */
    protected function getIdentifier($entity, $entityMeta = null)
    {
        $entityMeta      = $entityMeta ? $entityMeta : $this->em->getClassMetadata($this->getEntityClassName($entity));
        $identifierField = $entityMeta->getSingleIdentifierFieldName($entityMeta);

        return $entityMeta->getReflectionProperty($identifierField)->getValue($entity);
    }

    protected function checkAuditable($entityClassName)
    {
        if ($this->auditConfigProvider->hasConfig($entityClassName)
            && $this->auditConfigProvider->getConfig($entityClassName)->is('auditable')
        ) {
            $reflection    = new \ReflectionClass($entityClassName);
            $classMetadata = new ClassMetadata($reflection->getName());

            foreach ($reflection->getProperties() as $reflectionProperty) {
                $fieldName = $reflectionProperty->getName();

                if ($this->auditConfigProvider->hasConfig($entityClassName, $fieldName)
                    && ($fieldConfig = $this->auditConfigProvider->getConfig($entityClassName, $fieldName))
                    && $fieldConfig->is('auditable')
                ) {
                    $propertyMetadata         = new PropertyMetadata($entityClassName, $reflectionProperty->getName());
                    $propertyMetadata->method = '__toString';

                    $classMetadata->addPropertyMetadata($propertyMetadata);
                }
            }

            if (count($classMetadata->propertyMetadata)) {
                $this->addConfig($classMetadata);

                return true;
            }
        }

        return false;
    }

    /**
     * @param $entity
     * @return string
     */
    private function getEntityClassName($entity)
    {
        if (is_object($entity)) {
            return ClassUtils::getClass($entity);
        }

        return $entity;
    }

    /**
     * @param object $entity
     * @return string
     */
    protected function getEntityIdentifierString($entity)
    {
        $className = $this->getEntityClassName($entity);
        $metadata  = $this->em->getClassMetadata($className);

        return serialize($metadata->getIdentifierValues($entity));
    }
}
