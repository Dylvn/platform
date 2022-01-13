<?php

namespace Oro\Bundle\WorkflowBundle\Tests\Unit\Async;

use Oro\Bundle\WorkflowBundle\Async\TransitionTriggerMessage;
use Oro\Bundle\WorkflowBundle\Entity\BaseTransitionTrigger;
use Oro\Component\Testing\Unit\EntityTestCaseTrait;
use Oro\Component\Testing\Unit\EntityTrait;

class TransitionTriggerMessageTest extends \PHPUnit\Framework\TestCase
{
    use EntityTrait;
    use EntityTestCaseTrait;

    public function testToArray()
    {
        $triggerId = 42;
        $mainEntityId = ['id' => 105];

        $this->assertEquals(
            [
                TransitionTriggerMessage::TRANSITION_TRIGGER => $triggerId,
                TransitionTriggerMessage::MAIN_ENTITY => $mainEntityId
            ],
            $this->getTransitionTriggerMessage($triggerId, $mainEntityId)->toArray()
        );
    }

    /**
     * @dataProvider createFromJsonExceptionProvider
     */
    public function testCreateFromJsonException(mixed $json, string $exceptionClass, string $expectedMessage)
    {
        $this->expectException($exceptionClass);
        $this->expectExceptionMessage($expectedMessage);

        TransitionTriggerMessage::createFromJson($json);
    }

    public function createFromJsonExceptionProvider(): array
    {
        return [
            [
                'json' => null,
                'exceptionClass' => \InvalidArgumentException::class,
                'expectedMessage' => 'Given json should not be empty'
            ],
            [
                'json' => new \stdClass(),
                'exceptionClass' => \InvalidArgumentException::class,
                'expectedMessage' => 'Given json should not be empty'
            ],
            [
                'json' => 'data',
                'exceptionClass' => \JsonException::class,
                'expectedMessage' => 'Syntax error'
            ],
            [
                'json' => '',
                'exceptionClass' => \InvalidArgumentException::class,
                'expectedMessage' => 'Given json should not be empty'
            ]
        ];
    }

    public function testCreateFromJson()
    {
        $triggerId = 42;
        $mainEntityId = ['id' => 105];

        $this->assertEquals(
            $this->getTransitionTriggerMessage($triggerId, $mainEntityId),
            TransitionTriggerMessage::createFromJson($this->getJson($triggerId, $mainEntityId))
        );
        $this->assertEquals(
            $this->getTransitionTriggerMessage(null, null),
            TransitionTriggerMessage::createFromJson('{"test":"data"}')
        );
    }

    public function testCreate()
    {
        $triggerId = 42;
        $mainEntityId = ['id' => 105];

        $this->assertEquals(
            $this->getTransitionTriggerMessage($triggerId, $mainEntityId),
            TransitionTriggerMessage::create($this->getEventTrigger($triggerId), $mainEntityId)
        );
    }

    public function testAccessors()
    {
        $this->assertPropertyAccessors(
            $this->getTransitionTriggerMessage(null, null),
            [
                ['triggerId', 5, 0],
                ['mainEntityId', ['id' => 105]]
            ]
        );
    }

    private function getEventTrigger(int $id): BaseTransitionTrigger
    {
        $mock = $this->createMock(BaseTransitionTrigger::class);
        $mock->expects($this->any())
            ->method('getId')
            ->willReturn($id);

        return $mock;
    }

    private function getTransitionTriggerMessage(?int $triggerId, mixed $mainEntityId): TransitionTriggerMessage
    {
        return $this->getEntity(
            TransitionTriggerMessage::class,
            ['triggerId' => $triggerId, 'mainEntityId' => $mainEntityId]
        );
    }

    private function getJson(int $triggerId, mixed $mainEntityId): string
    {
        return sprintf(
            '{"%s":%d,"%s":%s}',
            TransitionTriggerMessage::TRANSITION_TRIGGER,
            $triggerId,
            TransitionTriggerMessage::MAIN_ENTITY,
            json_encode($mainEntityId)
        );
    }
}
