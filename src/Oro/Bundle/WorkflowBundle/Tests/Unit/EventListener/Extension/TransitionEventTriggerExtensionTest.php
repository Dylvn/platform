<?php

namespace Oro\Bundle\WorkflowBundle\Tests\Unit\EventListener\Extension;

use Oro\Bundle\MessageQueueBundle\Test\Unit\MessageQueueExtension;
use Oro\Bundle\WorkflowBundle\Async\TransitionTriggerMessage;
use Oro\Bundle\WorkflowBundle\Async\TransitionTriggerProcessor;
use Oro\Bundle\WorkflowBundle\Entity\EventTriggerInterface;
use Oro\Bundle\WorkflowBundle\Entity\Repository\TransitionEventTriggerRepository;
use Oro\Bundle\WorkflowBundle\Entity\TransitionEventTrigger;
use Oro\Bundle\WorkflowBundle\Entity\WorkflowDefinition;
use Oro\Bundle\WorkflowBundle\EventListener\Extension\TransitionEventTriggerExtension;
use Oro\Bundle\WorkflowBundle\Handler\TransitionEventTriggerHandler;
use Oro\Bundle\WorkflowBundle\Helper\TransitionEventTriggerHelper;

class TransitionEventTriggerExtensionTest extends AbstractEventTriggerExtensionTestCase
{
    use MessageQueueExtension;

    /** @var \PHPUnit\Framework\MockObject\MockObject|TransitionEventTriggerRepository */
    protected $repository;

    /** @var \PHPUnit\Framework\MockObject\MockObject|TransitionEventTriggerHelper */
    protected $helper;

    /** @var \PHPUnit\Framework\MockObject\MockObject|TransitionEventTriggerHandler */
    protected $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->getMockBuilder(TransitionEventTriggerRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findAllWithDefinitions'])
            ->getMock();

        $this->doctrineHelper->expects($this->any())
            ->method('getEntityRepositoryForClass')
            ->with(TransitionEventTrigger::class)
            ->willReturn($this->repository);

        $this->helper = $this->getMockBuilder(TransitionEventTriggerHelper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->handler = $this->getMockBuilder(TransitionEventTriggerHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->extension = new TransitionEventTriggerExtension(
            $this->doctrineHelper,
            $this->triggerCache,
            self::getMessageProducer(),
            $this->helper,
            $this->handler
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($this->helper, $this->handler, $this->repository);
    }

    /**
     * @dataProvider scheduleDataProvider
     *
     * @param string $event
     * @param array $triggers
     * @param array $changeSet
     */
    public function testScheduleCreateEvent($event, array $triggers, array $changeSet = [])
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

        $this->helper->expects(static::exactly(count($triggers)))
            ->method('isRequirePass')
            ->willReturnCallback(
                function ($trigger, $mainEntity, $prevEntity) use ($entity, $changeSet, $triggers) {
                    $expectedPrevEntity = $changeSet ?
                        $this->getMainEntity(self::ENTITY_ID, [self::FIELD => $changeSet[self::FIELD]['old']]) :
                        clone $entity;

                    static::assertEquals($expectedPrevEntity, $prevEntity);
                    static::assertSame($entity, $mainEntity);
                    static::assertTrue(in_array($trigger, $triggers));

                    return true;
                }
            );

        $extension = $this->mockTriggerExtensionDescendant();

        $this->callPreFunctionByEventName($event, $entity, $changeSet, $extension);

        $expectedTriggers = $this->getExpectedTriggers($this->getTriggers());
        $expectedSchedules = $this->getExpectedSchedules($triggers);

        static::assertEquals($expectedTriggers, $extension->xgetTriggers());
        static::assertEquals($expectedSchedules, $extension->xgetScheduled());
    }

    /**
     * @return array
     */
    public function scheduleDataProvider()
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
            'case when change set contains a field with missed setter' => [
                'event' => EventTriggerInterface::EVENT_UPDATE,
                'triggers' => ['updateEntity'],
                'changeSet' => [
                    'hidden_field' => ['old' => 'old_value', 'new' => 'new_value'],
                    self::FIELD => ['old' => 2, 'new' => 2]
                ]
            ],
            [
                'event' => EventTriggerInterface::EVENT_DELETE,
                'triggers' => ['delete']
            ]
        ];
    }

    public function testScheduleRequireNotPass()
    {
        $this->prepareRepository();
        $this->prepareTriggerCache(self::ENTITY_CLASS, EventTriggerInterface::EVENT_CREATE);

        $this->helper->expects($this->any())->method('isRequirePass')->willReturn(false);

        $extension = $this->mockTriggerExtensionDescendant();

        $this->callPreFunctionByEventName(EventTriggerInterface::EVENT_CREATE, $this->getMainEntity(), [], $extension);

        $expectedTriggers = $this->getExpectedTriggers($this->getTriggers());

        static::assertEquals($expectedTriggers, $extension->xgetTriggers());
        static::assertEmpty($extension->xgetScheduled());
    }

    /**
     * @dataProvider clearDataProvider
     *
     * @param string $className
     * @param bool $shouldHaveScheduled
     */
    public function testClear($className, $shouldHaveScheduled)
    {
        $this->prepareRepository();
        $this->prepareTriggerCache(self::ENTITY_CLASS, EventTriggerInterface::EVENT_CREATE);

        $this->helper->expects($this->any())->method('isRequirePass')->willReturn(true);

        $extension = $this->mockTriggerExtensionDescendant();

        $this->callPreFunctionByEventName(EventTriggerInterface::EVENT_CREATE, $this->getMainEntity(), [], $extension);

        static::assertEquals($this->getExpectedTriggers($this->getTriggers()), $extension->xgetTriggers());
        static::assertNotEmpty($extension->xgetScheduled());

        // test
        $extension->clear($className);

        static::assertNull($extension->xgetTriggers());

        if ($shouldHaveScheduled) {
            static::assertNotEmpty($extension->xgetScheduled());
        } else {
            static::assertEmpty($extension->xgetScheduled());
        }
    }

    /**
     * @return array
     */
    public function clearDataProvider()
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

        $this->helper->expects($this->any())->method('isRequirePass')->willReturn(true);
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

        $this->helper->expects($this->any())->method('isRequirePass')->willReturn(true);
        $this->helper->expects($this->any())->method('getMainEntity')->willReturn(null);

        $this->handler->expects($this->never())->method($this->anything());

        $this->callPreFunctionByEventName(EventTriggerInterface::EVENT_CREATE, $this->getMainEntity());

        $this->extension->process($this->entityManager);

        self::assertMessagesEmpty(TransitionTriggerProcessor::EVENT_TOPIC_NAME);
    }

    /**
     * @dataProvider processQueuedProvider
     *
     * @param bool $triggerQueued
     * @param bool $forceQueued
     */
    public function testProcessQueued($triggerQueued, $forceQueued)
    {
        $entity = $this->getMainEntity();

        /** @var TransitionEventTrigger $expectedTrigger */
        $expectedTrigger = $this->getTriggers('create');
        $expectedTrigger->setQueued($triggerQueued);

        $this->prepareRepository();
        $this->prepareTriggerCache(self::ENTITY_CLASS, EventTriggerInterface::EVENT_CREATE);

        $this->helper->expects($this->any())->method('isRequirePass')->willReturn(true);
        $this->helper->expects($this->once())
            ->method('getMainEntity')
            ->with($expectedTrigger, $entity)
            ->willReturn($entity);

        $this->handler->expects($this->never())->method($this->anything());

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

    /**
     * @return array
     */
    public function processQueuedProvider()
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
    protected function getTriggers($triggerName = null)
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
     * @return array
     */
    protected function getExpectedSchedules(array $triggers)
    {
        $expected = [];

        foreach ($triggers as $trigger) {
            $entityClass = $trigger->getEntityClass();

            $expected[$entityClass][] = ['trigger' => $trigger, 'entity' => $this->getMainEntity()];
        }

        return $expected;
    }

    /**
     * @return TransitionEventTriggerExtension
     */
    protected function mockTriggerExtensionDescendant()
    {
        return new class(
            $this->doctrineHelper,
            $this->triggerCache,
            self::getMessageProducer(),
            $this->helper,
            $this->handler
        ) extends TransitionEventTriggerExtension {
            public function xgetTriggers(): ?array
            {
                return $this->triggers;
            }

            public function xgetScheduled(): array
            {
                return $this->scheduled;
            }
        };
    }
}
