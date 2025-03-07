<?php

namespace Oro\Bundle\CommentBundle\Entity\Manager;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\AttachmentBundle\Provider\AttachmentProvider;
use Oro\Bundle\AttachmentBundle\Provider\PictureSourcesProviderInterface;
use Oro\Bundle\BatchBundle\ORM\Query\QueryCountCalculator;
use Oro\Bundle\BatchBundle\ORM\QueryBuilder\CountQueryBuilderOptimizer;
use Oro\Bundle\CommentBundle\Entity\Comment;
use Oro\Bundle\CommentBundle\Entity\Repository\CommentRepository;
use Oro\Bundle\EntityBundle\Exception\InvalidEntityException;
use Oro\Bundle\EntityBundle\Provider\EntityNameResolver;
use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SoapBundle\Entity\Manager\ApiEntityManager;
use Oro\Bundle\UIBundle\Tools\HtmlTagHelper;
use Oro\Bundle\UserBundle\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The API manager for Comment entity.
 */
class CommentApiManager extends ApiEntityManager
{
    const AVATAR_FIELD_NAME = 'avatar';

    /** @var ObjectManager */
    protected $em;

    /** @var CountQueryBuilderOptimizer */
    protected $countQueryBuilderOptimizer;

    /** @var AuthorizationCheckerInterface */
    protected $authorizationChecker;

    /** @var EntityNameResolver */
    protected $entityNameResolver;

    /** @var PictureSourcesProviderInterface */
    protected $pictureSourcesProvider;

    /** @var AttachmentProvider */
    protected $attachmentProvider;

    /** @var AclHelper */
    protected $aclHelper;

    /** @var ConfigManager */
    protected $configManager;

    /** @var HtmlTagHelper */
    protected $htmlTagHelper;

    public function __construct(
        ManagerRegistry $doctrine,
        AuthorizationCheckerInterface $authorizationChecker,
        EntityNameResolver $entityNameResolver,
        CountQueryBuilderOptimizer $countQueryBuilderOptimizer,
        EventDispatcherInterface $eventDispatcher,
        AttachmentProvider $attachmentProvider,
        AclHelper $aclHelper,
        ConfigManager $configManager
    ) {
        $this->em = $doctrine->getManager();
        $this->authorizationChecker = $authorizationChecker;
        $this->entityNameResolver = $entityNameResolver;
        $this->countQueryBuilderOptimizer = $countQueryBuilderOptimizer;
        $this->attachmentProvider = $attachmentProvider;
        $this->aclHelper = $aclHelper;
        $this->configManager = $configManager;

        parent::__construct(Comment::class, $this->em);

        $this->setEventDispatcher($eventDispatcher);
    }

    public function setHtmlTagHelper(HtmlTagHelper $htmlTagHelper)
    {
        $this->htmlTagHelper = $htmlTagHelper;
    }

    public function setPictureSourcesProvider(PictureSourcesProviderInterface $pictureSourcesProvider)
    {
        $this->pictureSourcesProvider = $pictureSourcesProvider;
    }

    /**
     * @param string $entityClass
     * @param int    $entityId
     * @param int    $page
     * @param int    $limit
     * @param array  $filters
     *
     * @return array
     */
    public function getCommentList($entityClass, $entityId, $page = 1, $limit = 10, $filters = [])
    {
        $entityName = $this->convertRelationEntityClassName($entityClass);
        $result     = [
            'count' => 0,
            'data'  => []
        ];

        if ($this->isCorrectClassName($entityName)) {
            $fieldName = $this->getFieldName($entityName);

            /** @var CommentRepository $repository */
            $repository = $this->getRepository();

            $qb = $repository->getBaseQueryBuilder($fieldName, $entityId);
            $qb->orderBy('c.createdAt', 'DESC');
            $this->addFilters($qb, $filters);

            $count = QueryCountCalculator::calculateCount(
                $this->aclHelper->apply($this->countQueryBuilderOptimizer->getCountQueryBuilder($qb))
            );

            $qb->setMaxResults($limit);
            $qb->setFirstResult($this->getOffset($page > 0 ? $page : 1, $limit));
            $data = $this->getEntityViewModels(
                $this->aclHelper->apply($qb)->execute(),
                $entityClass,
                $entityId
            );

            $result['data'] = $data;
            $result['count'] = $count;
        }

        return $result;
    }

    /**
     * @param string $entityClass
     * @param object[] $groupRelationEntities
     * @return int
     */
    public function getCommentCount($entityClass, $groupRelationEntities)
    {
        $result = 0;

        if ($this->isCommentable()) {
            $entityName = $this->convertRelationEntityClassName($entityClass);
            $entityIds = $this->prepareRelationEntityId($groupRelationEntities);

            try {
                if ($this->isCorrectClassName($entityName)) {
                    $result = $this->getBuildCommentCount($entityName, $entityIds);
                }
            } catch (\Exception $e) {
            }
        }
        return $result;
    }

    /**
     * @param Comment $entity
     * @param string  $entityClass
     * @param string  $entityId
     */
    public function setRelationField(Comment $entity, $entityClass, $entityId)
    {
        $entityName = $this->convertRelationEntityClassName($entityClass);

        if (!$this->isCorrectClassName($entityName)) {
            throw new InvalidEntityException('Invalid entity name ' . $entityName);
        }

        $relatedEntity = $this->getRelatedEntity($entityName, $entityId);
        $accessor      = PropertyAccess::createPropertyAccessor();

        $accessor->setValue($entity, $this->getFieldName($entityName), $relatedEntity);
    }

    /**
     * @param Comment $entity
     * @param string  $entityClass
     * @param string  $entityId
     *
     * @return array
     */
    public function getEntityViewModel(Comment $entity, $entityClass = '', $entityId = '')
    {
        $ownerName = '';
        $ownerId   = '';

        if ($entity->getOwner()) {
            $ownerName = $this->entityNameResolver->getName($entity->getOwner());
            $ownerId   = $entity->getOwner()->getId();
        }

        $editorName = '';
        $editorId   = '';

        if ($entity->getUpdatedBy()) {
            $editorName = $this->entityNameResolver->getName($entity->getUpdatedBy());
            $editorId   = $entity->getUpdatedBy()->getId();
        }

        $result = [
            'id'            => $entity->getId(),
            'owner'         => $ownerName,
            'owner_id'      => $ownerId,
            'editor'        => $editorName,
            'editor_id'     => $editorId,
            'message'       => $this->htmlTagHelper->sanitize($entity->getMessage()),
            'relationClass' => $entityClass,
            'relationId'    => $entityId,
            'createdAt'     => $entity->getCreatedAt()->format('c'),
            'updatedAt'     => $entity->getUpdatedAt()->format('c'),
            'editable'      => $this->authorizationChecker->isGranted('EDIT', $entity),
            'removable'     => $this->authorizationChecker->isGranted('DELETE', $entity),
        ];
        $result = array_merge($result, $this->attachmentProvider->getAttachmentInfo($entity));

        return array_merge($result, $this->getCommentAvatarImage($entity->getOwner()));
    }

    /**
     * @return bool
     */
    public function isCommentable()
    {
        return $this->authorizationChecker->isGranted('oro_comment_view');
    }

    /**
     * Get resized avatar
     *
     * @param User $user
     *
     * @return array
     */
    protected function getCommentAvatarImage($user): array
    {
        $attachment = PropertyAccess::createPropertyAccessor()->getValue($user, self::AVATAR_FIELD_NAME);
        if (($this->pictureSourcesProvider instanceof PictureSourcesProviderInterface) &&
            $attachment &&
            $attachment->getFilename()
        ) {
            $config = $this->configManager
                ->getProvider('attachment')
                ->getConfig(get_class($user), self::AVATAR_FIELD_NAME);

            return [
                'avatarPicture' => $this->pictureSourcesProvider->getResizedPictureSources(
                    $attachment,
                    $config->get('width'),
                    $config->get('height')
                )
            ];
        }

        return [];
    }

    /**
     * Adds filters to a query builder
     *
     * @param QueryBuilder   $qb
     * @param array|Criteria $filters Additional filtering criteria, e.g. ['allDay' => true, ...]
     *                                or \Doctrine\Common\Collections\Criteria
     */
    protected function addFilters(QueryBuilder $qb, $filters)
    {
        if ($filters) {
            if (is_array($filters)) {
                $newCriteria = new Criteria();
                foreach ($filters as $fieldName => $value) {
                    $newCriteria->andWhere(Criteria::expr()->eq($fieldName, $value));
                }

                $filters = $newCriteria;
            }

            if ($filters instanceof Criteria) {
                $qb->addCriteria($filters);
            }
        }
    }

    /**
     * @param string $entityName
     * @param int    $entityId
     *
     * @return Object Returns instance of $entityName
     *
     * @throws EntityNotFoundException
     */
    protected function getRelatedEntity($entityName, $entityId)
    {
        $repository    = $this->getObjectManager()->getRepository($entityName);
        $relatedEntity = $repository->findOneById($entityId);

        if (empty($relatedEntity)) {
            throw new EntityNotFoundException();
        }

        return $relatedEntity;
    }

    /**
     * @param string $entityName
     *
     * @return string
     */
    protected function getFieldName($entityName)
    {
        return ExtendHelper::buildAssociationName($entityName);
    }

    /**
     * @param string $entityClass
     *
     * @return string
     */
    protected function convertRelationEntityClassName($entityClass)
    {
        return str_replace('_', '\\', $entityClass);
    }

    /**
     * @param Comment[] $entities
     * @param string    $entityClass
     * @param string    $entityId
     *
     * @return array
     */
    protected function getEntityViewModels($entities, $entityClass, $entityId)
    {
        $result = [];

        foreach ($entities as $entity) {
            $result[] = $this->getEntityViewModel($entity, $entityClass, $entityId);
        }

        return $result;
    }

    /**
     * @param string $entityName
     *
     * @return bool
     */
    protected function isCorrectClassName($entityName)
    {
        try {
            $classMetadata = $this->em->getMetadataFactory()->getMetadataFor($entityName);
            $classMetadata->getName();
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @param string $entityName
     * @param int[] $entityIds
     *
     * @return int important to return int
     */
    protected function getBuildCommentCount($entityName, $entityIds)
    {
        /** @var CommentRepository $repository */
        $fieldName  = $this->getFieldName($entityName);
        $repository = $this->getRepository();
        $qb         = $repository->getNumberOfComment($fieldName, $entityIds);
        $query      = $this->aclHelper->apply($qb);

        return  (int) $query->getSingleScalarResult();
    }

    /**
     * @param object[] $groupRelationEntities
     * @return int[]
     */
    protected function prepareRelationEntityId($groupRelationEntities)
    {
        $relatedActivityId = [];
        foreach ($groupRelationEntities as $activityEntity) {
            $relatedActivityId[] = $activityEntity->getRelatedActivityId();
        }

        return $relatedActivityId;
    }
}
