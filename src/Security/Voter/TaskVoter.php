<?php

namespace App\Security\Voter;

use App\Entity\Task;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Task authorization: tasks are personal work items — visible and modifiable
 * only by their creator, their assignee, and administrators. This closes the
 * IDOR where any ROLE_USER could read/mutate any task by numeric ID and the
 * ?all=1 scope bypass on the list/Kanban endpoints.
 */
/**
 * @extends Voter<string, Task|null>
 */
class TaskVoter extends Voter
{
    /** Read a single task (show page, detail panels). */
    public const VIEW = 'TASK_VIEW';

    /** Mutate a task: edit, toggle, complete, status change, reorder. */
    public const MODIFY = 'TASK_MODIFY';

    /** Delete an open task / archive a completed one. */
    public const ARCHIVE = 'TASK_ARCHIVE';

    /** List every user's tasks (index/Kanban "all" scope). Admins only. */
    public const VIEW_ALL = 'TASK_VIEW_ALL';

    public function __construct(private Security $security) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute === self::VIEW_ALL) {
            return true; // scope query, no subject
        }

        return in_array($attribute, [self::VIEW, self::MODIFY, self::ARCHIVE], true)
            && $subject instanceof Task;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($attribute === self::VIEW_ALL) {
            return $this->security->isGranted('ROLE_ADMIN');
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        /** @var Task $task */
        $task = $subject;

        $isOwn = $task->getCreatedBy()?->getId() === $user->getId()
            || $task->getAssignedTo()?->getId() === $user->getId();

        return match ($attribute) {
            self::VIEW, self::MODIFY, self::ARCHIVE => $isOwn,
            // Unreachable: supports() only admits the four attributes above.
            default => false,
        };
    }
}
