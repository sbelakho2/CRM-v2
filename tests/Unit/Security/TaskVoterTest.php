<?php

namespace App\Tests\Unit\Security;

use App\Entity\Task;
use App\Entity\User;
use App\Security\Voter\TaskVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Critical-surface coverage: the TaskVoter is THE task authorization
 * decision (the round-8 IDOR fix). Direct matrix test — every attribute ×
 * every principal — because the functional controller tests only prove the
 * happy paths the crawl happens to hit.
 */
class TaskVoterTest extends TaskVoterTestBase
{
    public function testCreatorCanViewModifyAndArchiveOwnTask(): void
    {
        $creator = $this->user(1);
        $task = $this->task($creator, $creator);

        foreach ([TaskVoter::VIEW, TaskVoter::MODIFY, TaskVoter::ARCHIVE] as $attribute) {
            $this->assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->vote($creator, $task, $attribute),
                "creator must be granted {$attribute} on their own task"
            );
        }
    }

    public function testAssigneeCanViewModifyAndArchiveAssignedTask(): void
    {
        $creator = $this->user(1);
        $assignee = $this->user(2);
        $task = $this->task($creator, $assignee);

        foreach ([TaskVoter::VIEW, TaskVoter::MODIFY, TaskVoter::ARCHIVE] as $attribute) {
            $this->assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->vote($assignee, $task, $attribute),
                "assignee must be granted {$attribute}"
            );
        }
    }

    public function testOutsiderIsDeniedEveryAttribute(): void
    {
        $creator = $this->user(1);
        $outsider = $this->user(3);
        $task = $this->task($creator, $creator);

        foreach ([TaskVoter::VIEW, TaskVoter::MODIFY, TaskVoter::ARCHIVE] as $attribute) {
            $this->assertSame(
                VoterInterface::ACCESS_DENIED,
                $this->vote($outsider, $task, $attribute),
                "a non-involved user must be DENIED {$attribute} — this is the round-8 IDOR"
            );
        }
    }

    public function testAdminIsGrantedEverything(): void
    {
        $task = $this->task($this->user(1), $this->user(1));
        $admin = $this->user(9, ['ROLE_ADMIN']);

        foreach ([TaskVoter::VIEW, TaskVoter::MODIFY, TaskVoter::ARCHIVE] as $attribute) {
            $this->assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->vote($admin, $task, $attribute),
                "admin must be granted {$attribute} on any task"
            );
        }
    }

    public function testViewAllIsAdminOnly(): void
    {
        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($this->user(9, ['ROLE_ADMIN']), null, TaskVoter::VIEW_ALL),
            'VIEW_ALL (the ?all=1 scope) must be granted to admins'
        );
        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->user(2), null, TaskVoter::VIEW_ALL),
            'VIEW_ALL must be DENIED to normal users — the ?all=1 leak'
        );
    }

    public function testAnonymousTokenIsDenied(): void
    {
        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')->willReturn(null);
        $voter = new TaskVoter($this->securityMock(false, false));
        $task = $this->task($this->user(1), $this->user(1));

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $task, [TaskVoter::VIEW]),
            'an unauthenticated token must never pass the voter'
        );
    }

    public function testForeignTaskClassIsAbstained(): void
    {
        $token = $this->tokenFor($this->user(1));
        $voter = new TaskVoter($this->securityMock(false, false));

        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, new \stdClass(), [TaskVoter::VIEW]),
            'the voter must not rule on subjects it does not support'
        );
    }
}
