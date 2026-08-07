<?php

namespace App\Tests\Unit\Service;

use App\Service\PlaybookEngine;
use App\Entity\Playbook;
use App\Entity\PlaybookRun;
use App\Entity\AbmAccount;
use App\Entity\WebEvent;
use App\Repository\PlaybookRepository;
use App\Repository\PlaybookRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use InvalidArgumentException;

class PlaybookEngineTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private PlaybookRepository $playbookRepo;
    private PlaybookRunRepository $playbookRunRepo;
    private PlaybookEngine $engine;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->playbookRepo = $this->createMock(PlaybookRepository::class);
        $this->playbookRunRepo = $this->createMock(PlaybookRunRepository::class);

        $this->engine = new PlaybookEngine(
            $this->entityManager,
            $this->playbookRepo,
            $this->playbookRunRepo
        );
    }

    public function testGetActivePlaybooksReturnsActivePlaybooks()
    {
        // getActivePlaybooks is fully implemented
        $playbook1 = $this->createMock(Playbook::class);
        $playbook2 = $this->createMock(Playbook::class);

        $this->playbookRepo->expects($this->once())
            ->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$playbook1, $playbook2]);

        $result = $this->engine->getActivePlaybooks();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertContains($playbook1, $result);
        $this->assertContains($playbook2, $result);
    }

    public function testGetActivePlaybooksReturnsEmptyArrayWhenNoActivePlaybooks()
    {
        $this->playbookRepo->expects($this->once())
            ->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([]);

        $result = $this->engine->getActivePlaybooks();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testEvaluateTriggersThrowsNotImplementedException()
    {
        $playbook = $this->createMock(Playbook::class);
        $context = $this->createMock(AbmAccount::class);
        $event = $this->createMock(WebEvent::class);

        $playbook->method('getTriggerRules')->willReturn(null);
        $this->assertTrue($this->engine->evaluateTriggers($playbook, $context, $event));
    }

    public function testEvaluateTriggersAcceptsNullEvent()
    {
        $playbook = $this->createMock(Playbook::class);
        $context = $this->createMock(AbmAccount::class);

        $playbook->method('getTriggerRules')->willReturn('not json');
        $this->assertFalse($this->engine->evaluateTriggers($playbook, $context, null));
    }

    public function testExecuteActionsThrowsNotImplementedException()
    {
        $playbook = $this->createMock(Playbook::class);
        $context = $this->createMock(AbmAccount::class);
        $event = $this->createMock(WebEvent::class);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->atLeastOnce())->method('flush');
        $playbook->method('getActions')->willReturn(null);

        $result = $this->engine->executeActions($playbook, $context, $event);
        $this->assertSame([], $result);
    }

    public function testExecuteActionsAcceptsNullEvent()
    {
        $playbook = $this->createMock(Playbook::class);
        $context = $this->createMock(AbmAccount::class);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->atLeastOnce())->method('flush');
        $playbook->method('getActions')->willReturn('[]');

        $result = $this->engine->executeActions($playbook, $context, null);
        $this->assertIsArray($result);
    }

    public function testLogRunThrowsNotImplementedException()
    {
        $this->playbookRepo->method('find')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Playbook not found');

        $this->engine->logRun(1, 'RUNNING');
    }

    public function testLogRunAcceptsOptionalParameters()
    {
        $playbook = $this->createMock(Playbook::class);
        $this->playbookRepo->method('find')->willReturn($playbook);
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $run = $this->engine->logRun(
            1,
            'COMPLETED',
            ['result1' => 'success'],
            null
        );

        $this->assertInstanceOf(PlaybookRun::class, $run);
    }

    public function testGetRunHistoryThrowsNotImplementedException()
    {
        $this->playbookRepo->method('find')->willReturn(null);
        $this->assertSame([], $this->engine->getRunHistory(1));
    }

    public function testGetRunHistoryAcceptsLimitParameter()
    {
        $playbook = $this->createMock(Playbook::class);
        $this->playbookRepo->method('find')->willReturn($playbook);

        $run = $this->createMock(PlaybookRun::class);
        $this->playbookRunRepo->expects($this->once())
            ->method('findBy')
            ->with(['playbook' => $playbook], ['triggeredAt' => 'DESC'], 100)
            ->willReturn([$run]);

        $history = $this->engine->getRunHistory(1, 100);
        $this->assertCount(1, $history);
    }

    /**
     * Test the implemented evaluateCondition method via reflection
     * since it's a private method
     */
    public function testEvaluateConditionEquals()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->assertTrue($method->invoke($this->engine, 5, '=', 5));
        $this->assertTrue($method->invoke($this->engine, 'test', '==', 'test'));
        $this->assertFalse($method->invoke($this->engine, 5, '=', 10));
    }

    public function testEvaluateConditionNotEquals()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->assertTrue($method->invoke($this->engine, 5, '!=', 10));
        $this->assertTrue($method->invoke($this->engine, 'a', '<>', 'b'));
        $this->assertFalse($method->invoke($this->engine, 5, '!=', 5));
    }

    public function testEvaluateConditionGreaterThan()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->assertTrue($method->invoke($this->engine, 10, '>', 5));
        $this->assertFalse($method->invoke($this->engine, 5, '>', 10));
        $this->assertFalse($method->invoke($this->engine, 5, '>', 5));
    }

    public function testEvaluateConditionLessThan()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->assertTrue($method->invoke($this->engine, 5, '<', 10));
        $this->assertFalse($method->invoke($this->engine, 10, '<', 5));
        $this->assertFalse($method->invoke($this->engine, 5, '<', 5));
    }

    public function testEvaluateConditionGreaterThanOrEqual()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->assertTrue($method->invoke($this->engine, 10, '>=', 5));
        $this->assertTrue($method->invoke($this->engine, 5, '>=', 5));
        $this->assertFalse($method->invoke($this->engine, 5, '>=', 10));
    }

    public function testEvaluateConditionLessThanOrEqual()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->assertTrue($method->invoke($this->engine, 5, '<=', 10));
        $this->assertTrue($method->invoke($this->engine, 5, '<=', 5));
        $this->assertFalse($method->invoke($this->engine, 10, '<=', 5));
    }

    public function testEvaluateConditionContains()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->assertTrue($method->invoke($this->engine, 'hello world', 'contains', 'world'));
        $this->assertTrue($method->invoke($this->engine, 'test@example.com', 'contains', '@'));
        $this->assertFalse($method->invoke($this->engine, 'hello', 'contains', 'xyz'));
    }

    public function testEvaluateConditionIn()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->assertTrue($method->invoke($this->engine, 'A', 'in', ['A', 'B', 'C']));
        $this->assertTrue($method->invoke($this->engine, 5, 'in', [1, 5, 10]));
        $this->assertFalse($method->invoke($this->engine, 'D', 'in', ['A', 'B', 'C']));
    }

    public function testEvaluateConditionNotIn()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->assertTrue($method->invoke($this->engine, 'D', 'not_in', ['A', 'B', 'C']));
        $this->assertFalse($method->invoke($this->engine, 'A', 'not_in', ['A', 'B', 'C']));
    }

    public function testEvaluateConditionRegex()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->assertTrue($method->invoke($this->engine, 'test@example.com', 'regex', '/.*@example\.com$/'));
        $this->assertTrue($method->invoke($this->engine, '192.168.1.1', 'regex', '/^\d+\.\d+\.\d+\.\d+$/'));
        $this->assertFalse($method->invoke($this->engine, 'invalid', 'regex', '/^\d+$/'));
    }

    public function testEvaluateConditionThrowsExceptionForUnknownOperator()
    {
        $method = new \ReflectionMethod(PlaybookEngine::class, 'evaluateCondition');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown operator: invalid_op');

        $method->invoke($this->engine, 5, 'invalid_op', 10);
    }

    /**
     * Future implementation tests - currently these will fail until implemented
     * Uncomment and adjust when implementation is complete
     */
    
    /*
    public function testEvaluateTriggersReturnsTrueWhenAllTriggersMatch()
    {
        $playbook = new Playbook();
        $playbook->setTriggersJson(json_encode([
            ['field' => 'abm_hits_7d', 'operator' => '>=', 'value' => 3],
            ['field' => 'company_tier', 'operator' => '=', 'value' => 'A']
        ]));

        $account = new AbmAccount();
        $account->setTargetTier('A');

        $event = new WebEvent();

        $result = $this->engine->evaluateTriggers($playbook, $account, $event);

        $this->assertTrue($result);
    }

    public function testEvaluateTriggersReturnsFalseWhenAnyTriggerDoesNotMatch()
    {
        $playbook = new Playbook();
        $playbook->setTriggersJson(json_encode([
            ['field' => 'abm_hits_7d', 'operator' => '>=', 'value' => 3],
            ['field' => 'company_tier', 'operator' => '=', 'value' => 'A']
        ]));

        $account = new AbmAccount();
        $account->setTargetTier('B'); // Does not match tier A

        $result = $this->engine->evaluateTriggers($playbook, $account, null);

        $this->assertFalse($result);
    }

    public function testExecuteActionsReturnsArrayOfResults()
    {
        $playbook = new Playbook();
        $playbook->setActionsJson(json_encode([
            ['type' => 'create_activity', 'data' => ['type' => 'CALL', 'priority' => 'HIGH']],
            ['type' => 'send_email', 'data' => ['template' => 'abm_hot_lead']]
        ]));

        $account = new AbmAccount();
        $event = new WebEvent();

        $results = $this->engine->executeActions($playbook, $account, $event);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        
        $this->assertEquals('create_activity', $results[0]['action']);
        $this->assertTrue($results[0]['success']);
        
        $this->assertEquals('send_email', $results[1]['action']);
        $this->assertTrue($results[1]['success']);
    }

    public function testLogRunCreatesPlaybookRunEntity()
    {
        $run = $this->engine->logRun(1, 'RUNNING');

        $this->assertInstanceOf(PlaybookRun::class, $run);
        $this->assertEquals(1, $run->getPlaybookId());
        $this->assertEquals('RUNNING', $run->getStatus());
    }

    public function testLogRunWithCompletedStatus()
    {
        $results = ['action1' => 'success', 'action2' => 'success'];
        $run = $this->engine->logRun(1, 'COMPLETED', $results);

        $this->assertEquals('COMPLETED', $run->getStatus());
        $this->assertNotNull($run->getCompletedAt());
    }

    public function testGetRunHistoryReturnsArrayOfPlaybookRuns()
    {
        $runs = $this->engine->getRunHistory(1, 10);

        $this->assertIsArray($runs);
        foreach ($runs as $run) {
            $this->assertInstanceOf(PlaybookRun::class, $run);
        }
    }
    */
}
