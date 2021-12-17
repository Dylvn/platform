<?php

namespace Oro\Bundle\WorkflowBundle\Tests\Unit\EventListener\Extension;

use Oro\Bundle\MessageQueueBundle\Test\Unit\MessageQueueExtension;
use Oro\Bundle\WorkflowBundle\Async\TransitionTriggerMessage;
use Oro\Bundle\WorkflowBundle\Async\TransitionTriggerProcessor;
use Oro\Bundle\WorkflowBundle\Entity\EventTriggerInterface;
use Oro\Bundle\WorkflowBundle\Entity\Repository\TransitionEventTriggerRepository;
use Oro\Bundle\WorkflowBundle\Entity\TransitionEventTrigger;
use Oro\Bundle\WorkflowBundle\Entity\WorkflowDefinition;
use Oro\Bundle\WorkflowBundle\EventListener\Extension\AbstractEventTriggerExtension;
use Oro\Bundle\WorkflowBundle\EventListener\Extension\TransitionEventTriggerExtension;
use Oro\Bundle\WorkflowBundle\Handler\TransitionEventTriggerHandler;
use Oro\Bundle\WorkflowBundle\Helper\TransitionEventTriggerHelper;
use Oro\Bundle\WorkflowBundle\Tests\Unit\EventListener\Stubs\WorkflowAwareEntityProxyStub;
use Oro\Component\Testing\ReflectionUtil;

class TransitionEventTriggerExtensionTest extends AbstractEventTriggerExtensionTestCase
{
    use MessageQueueExtension;

    protected const ENTITY_CLASS = WorkflowAwareEntityProxyStub::class;

    /** @var TransitionEventTriggerRepository|\PHPUnit\Framework\MockObject\MockObject */
    protected $repository;

    /** @var TransitionEventTriggerHelper|\PHPUnit\Framework\MockObject\MockObject */
    private $helper;

    /** @var TransitionEventTriggerHandler|\PHPUnit\Framework\MockObject\MockObject */
    private $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(TransitionEventTriggerRepository::class);
        $this->helper = $this->createMock(TransitionEventTriggerHelper::class);
        $this->handler = $this->createMock(TransitionEventTriggerHandler::class);

        $this->doctrineHelper->expects($this->any())
            ->method('getEntityRepositoryForClass')
            ->with(TransitionEventTrigger::class)
            ->willReturn($this->repository);

        $this->extension = new TransitionEventTriggerExtension(
            $this->doctrineHelper,
            $this->triggerCache,
            self::getMessageProducer(),
            $this->helper,
            $this->handler
        );
    }

    /**
     * @dataProvider scheduleDataProvider
     */
    public function testScheduleCreateEvent(string $event, array $triggers, array $changeSet = [])
    {
        $entity = $this->getMainEntity();

        $this->prepareRepository();
        $this->prepareTriggerCache(self::ENTITY_CLASS, $event);

        $triggers = array_map(
            function ($name) {
                return $this->getTriggers($name);
            },
            $triggers
        );

        $this->helper->expects(self::exactly(count($triggers)))
            ->method('isRequirePass')
            ->willReturnCallback(function ($trigger, $mainEntity, $prevEntity) use ($entity, $changeSet, $triggers) {
                $expectedPrevEntity = $changeSet ?
                    $this->getMainEntity(self::ENTITY_ID, [self::FIELD => $changeSet[self::FIELD]['old']]) :
                    clone $entity;

                self::assertEquals($expectedPrevEntity, $prevEntity);
                self::assertSame($entity, $mainEntity);
                self::assertContains($trigger, $triggers);

                return true;
            });

        $this->callPreFunctionByEventName($event, $entity, $changeSet, $this->extension);

        $expectedTriggers = $this->getExpectedTriggers($this->getTriggers());
        $expectedSchedules = $this->getExpectedSchedules($triggers);

        self::assertEquals($expectedTriggers, $this->getExtensionTriggers($this->extension));
        self::assertEquals($expectedSchedules, $this->getExtensionScheduled($this->extension));
    }

    public function scheduleDataProvider(): array
    {
        return [
            [
                'event' => EventTriggerInterface::EVENT_CREATE,
                'triggers' => ['create']
            ],
            [
                'event' => EventTriggerInterface::EVENT_UPDATE,
                'triggers' => ['updateEntity', 'updateField'],
                'changeSet' => [self::FIELD => ['old' => 1, 'new' => 2]]
            ],
            [
                'event' => EventTriggerInterface::EVENT_UPDATE,
                'triggers' => ['updateEntity'],
                'changeSet' => [self::FIELD => ['old' => 2, 'new' => 2]]
            ],
            [
                'event' => EventTriggerInterface::EVENT_DELETE,
                'triggers' => ['delete']
            ],
            'case when change set contains a field with missed setter' => [
                'event' => EventTriggerInterface::EVENT_UPDATE,
                'triggers' => ['updateEntity'],
                'changeSet' => [
                    'hiddenProperty' => ['old' => 'hiddenPropertyValue', 'new' => 'hiddenPropertyValueModified'],
                    self::FIELD => ['old' => 2, 'new' => 2]
                ]
            ],
        ];
    }

    public function testScheduleRequireNotPass()
    {
        $this->prepareRepository();
        $this->prepareTriggerCache(self::ENTITY_CLASS, EventTriggerInterface::EVENT_CREATE);

        $this->helper->expects($this->any())
            ->method('isRequirePass')
            ->willReturn(false);

        $this->callPreFunctionByEventName(
            EventTriggerInterface::EVENT_CREATE,
            $this->getMainEntity(),
            [],
            $this->extension
        );

        $expectedTriggers = $this->getExpectedTriggers($this->getTriggers());

        self::assertEquals($expectedTriggers, $this->getExtensionTriggers($this->extension));
        self::assertEmpty($this->getExtensionScheduled($this->extension));
    }

    /**
     * @dataProvider clearDataProvider
     */
    public function testClear(?string $className, bool $shouldHaveScheduled)
    {
        $this->prepareRepository();
        $this->prepareTriggerCache(self::ENTITY_CLASS, EventTriggerInterface::EVENT_CREATE);

        $this->helper->expects($this->any())
            ->method('isRequirePass')
            ->willReturn(true);

        $this->callPreFunctionByEventName(
            EventTriggerInterface::EVENT_CREATE,
            $this->getMainEntity(),
            [],
            $this->extension
        );

        self::assertEquals(
            $this->getExpectedTriggers($this->getTriggers()),
            $this->getExtensionTriggers($this->extension)
        );
        self::assertNotEmpty($this->getExtensionScheduled($this->extension));

        // test
        $this->extension->clear($className);

        self::assertNull($this->getExtensionTriggers($this->extension));

        if ($shouldHaveScheduled) {
            self::assertNotEmpty($this->getExtensionScheduled($this->extension));
        } else {
            self::assertEmpty($this->getExtensionScheduled($this->extension));
        }
    }

    public function clearDataProvider(): array
    {
        return [
            'clear all' => [
                'className' => null,
                'hasScheduledProcesses' => false
            ],
            'clear scheduled processes' => [
                'className' => self::ENTITY_CLASS,
                'hasScheduledProcesses' => false
            ],
            'clear event triggers' => [
                'className' => TransitionEventTrigger::class,
                'hasScheduledProcesses' => true
            ]
        ];
    }

    public function testProcessNotQueued()
    {
        $entityClass = self::ENTITY_CLASS;
        $entity = $this->getMainEntity(null);

        $mainEntity = $this->getMainEntity();

        /** @var TransitionEventTrigger $expectedTrigger */
        $expectedTrigger = $this->getTriggers('create');
        $expectedTrigger->setQueued(false)->setTransitionName('test_transition');

        $this->prepareRepository();
        $this->prepareTriggerCache($entityClass, EventTriggerInterface::EVENT_CREATE);

        $this->helper->expects($this->any())
            ->method('isRequirePass')
            ->willReturn(true);
        $this->helper->expects($this->once())
            ->method('getMainEntity')
            ->with($expectedTrigger, $entity)
            ->willReturn($mainEntity);

        $this->handler->expects($this->once())
            ->method('process')
            ->with($expectedTrigger, TransitionTriggerMessage::create($expectedTrigger, null))
            ->willReturn(true);

        $this->callPreFunctionByEventName(EventTriggerInterface::EVENT_CREATE, $entity);

        $this->extension->process($this->entityManager);

        self::assertMessagesEmpty(TransitionTriggerProcessor::EVENT_TOPIC_NAME);
    }

    public function testProcessWithoutMainEntity()
    {
        $this->prepareRepository();
        $this->prepareTriggerCache(self::ENTITY_CLASS, EventTriggerInterface::EVENT_CREATE);

        $this->helper->expects($this->any())
            ->method('isRequirePass')
            ->willReturn(true);
        $this->helper->expects($this->any())
            ->method('getMainEntity')
            ->willReturn(null);

        $this->handler->expects($this->never())
            ->method($this->anything());

        $this->callPreFunctionByEventName(EventTriggerInterface::EVENT_CREATE, $this->getMainEntity());

        $this->extension->process($this->entityManager);

        self::assertMessagesEmpty(TransitionTriggerProcessor::EVENT_TOPIC_NAME);
    }

    /**
     * @dataProvider processQueuedProvider
     */
    public function testProcessQueued(bool $triggerQueued, bool $forceQueued)
    {
        $entity = $this->getMainEntity();

        /** @var TransitionEventTrigger $expectedTrigger */
        $expectedTrigger = $this->getTriggers('create');
        $expectedTrigger->setQueued($triggerQueued);

        $this->prepareRepository();
        $this->prepareTriggerCache(self::ENTITY_CLASS, EventTriggerInterface::EVENT_CREATE);

        $this->helper->expects($this->any())
            ->method('isRequirePass')
            ->willReturn(true);
        $this->helper->expects($this->once())
            ->method('getMainEntity')
            ->with($expectedTrigger, $entity)
            ->willReturn($entity);

        $this->handler->expects($this->never())
            ->method($this->anything());

        $this->callPreFunctionByEventName(EventTriggerInterface::EVENT_CREATE, $entity);

        $this->extension->setForceQueued($forceQueued);
        $this->extension->process($this->entityManager);

        self::assertMessageSent(
            TransitionTriggerProcessor::EVENT_TOPIC_NAME,
            [
                TransitionTriggerMessage::TRANSITION_TRIGGER => $expectedTrigger->getId(),
                TransitionTriggerMessage::MAIN_ENTITY => null
            ]
        );
    }

    public function processQueuedProvider(): array
    {
        return [
            'queued' => [
                'triggerQueued' => true,
                'forceQueued' => false
            ],
            'force queued' => [
                'triggerQueued' => false,
                'forceQueued' => true
            ]
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getTriggers(string $triggerName = null): array|object
    {
        if (!$this->triggers) {
            $priority = 0;

            $definition = new WorkflowDefinition();
            $definition->setName('test')->setRelatedEntity(self::ENTITY_CLASS)->setPriority($priority);

            $createTrigger = $this->getEntity(
                TransitionEventTrigger::class,
                [
                    'id' => 42,
                    'workflowDefinition' => $definition,
                    'event' => EventTriggerInterface::EVENT_CREATE,
                    'transitionName' => 'test_transition'
                ]
            );

            $updateEntityTrigger = new TransitionEventTrigger();
            $updateEntityTrigger->setWorkflowDefinition($definition)
                ->setEvent(EventTriggerInterface::EVENT_UPDATE)
                ->setQueued(true);

            $updateFieldTrigger = new TransitionEventTrigger();
            $updateFieldTrigger->setWorkflowDefinition($definition)
                ->setEvent(EventTriggerInterface::EVENT_UPDATE)
                ->setField(self::FIELD);

            $deleteTrigger = new TransitionEventTrigger();
            $deleteTrigger->setWorkflowDefinition($definition)->setEvent(EventTriggerInterface::EVENT_DELETE);

            $this->triggers = [
                'create' => $createTrigger,
                'updateEntity' => $updateEntityTrigger,
                'updateField' => $updateFieldTrigger,
                'delete' => $deleteTrigger,
            ];
        }

        return $triggerName ? $this->triggers[$triggerName] : $this->triggers;
    }

    /**
     * @param TransitionEventTrigger[] $triggers
     *
     * @return array
     */
    private function getExpectedSchedules(array $triggers): array
    {
        $expected = [];
        foreach ($triggers as $trigger) {
            $entityClass = $trigger->getEntityClass();
            $expected[$entityClass][] = ['trigger' => $trigger, 'entity' => $this->getMainEntity()];
        }

        return $expected;
    }

    private function getExtensionTriggers(AbstractEventTriggerExtension $extension): mixed
    {
        return ReflectionUtil::getPropertyValue($extension, 'triggers');
    }

    private function getExtensionScheduled(AbstractEventTriggerExtension $extension): mixed
    {
        return ReflectionUtil::getPropertyValue($extension, 'scheduled');
    }
}
