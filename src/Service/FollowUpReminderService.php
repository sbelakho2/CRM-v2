<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\CompanyRepository;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Follow-up Reminder Service
 * 
 * Manages automated follow-up scheduling and reminders:
 * - Schedules follow-ups based on activity type and outcome
 * - Tracks overdue follow-ups
 * - Generates reminder notifications
 * - Integrates with activity logging
 */
class FollowUpReminderService
{
    // Follow-up timing rules (days until follow-up)
    private const FOLLOWUP_RULES = [
        // Activity type => [outcome => days until follow-up]
        'email' => [
            'sent' => 3,             // Follow up 3 days after sending
            'no_response' => 7,      // Re-follow if no response after 7 days
            'opened' => 2,           // Quick follow up if opened
            'clicked' => 1,          // Very quick if engaged
        ],
        'call' => [
            'connected' => 7,        // Schedule next call in a week
            'voicemail' => 2,        // Try again in 2 days
            'no_answer' => 1,        // Try again tomorrow
            'callback_requested' => 0, // Same day callback
        ],
        'meeting' => [
            'completed' => 3,        // Send follow-up summary in 3 days
            'no_show' => 1,          // Reschedule immediately
            'rescheduled' => 0,      // Note the new date
        ],
        'proposal_sent' => [
            'sent' => 5,             // Check in after 5 days
            'reviewing' => 7,        // Allow time for review
            'questions' => 1,        // Answer questions quickly
        ],
        'demo' => [
            'completed' => 2,        // Follow up while fresh
            'rescheduled' => 0,
        ],
        'site_visit' => [
            'completed' => 5,        // Send summary and next steps
        ],
        'rfq_received' => [
            'received' => 2,         // Acknowledge and quote
            'quoted' => 5,           // Follow up on quote
        ],
    ];
    
    // Priority levels
    public const PRIORITY_URGENT = 'urgent';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_LOW = 'low';
    
    private EntityManagerInterface $entityManager;
    private ActivityRepository $activityRepository;
    private CompanyRepository $companyRepository;
    private ContactRepository $contactRepository;
    private LoggerInterface $logger;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        ActivityRepository $activityRepository,
        CompanyRepository $companyRepository,
        ContactRepository $contactRepository,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->activityRepository = $activityRepository;
        $this->companyRepository = $companyRepository;
        $this->contactRepository = $contactRepository;
        $this->logger = $logger;
    }
    
    /**
     * Schedule a follow-up based on an activity
     */
    public function scheduleFollowUp(
        Activity $sourceActivity,
        string $outcome,
        ?string $customNote = null,
        ?\DateTimeInterface $customDate = null
    ): ?array {
        $activityType = strtolower($sourceActivity->getType() ?? 'email');
        
        // Determine follow-up date
        $followUpDate = $customDate;
        
        if (!$followUpDate) {
            $daysUntilFollowUp = $this->getFollowUpDays($activityType, $outcome);
            if ($daysUntilFollowUp === null) {
                return null; // No follow-up needed
            }
            
            $followUpDate = (new \DateTime())->modify("+{$daysUntilFollowUp} days");
        }
        
        // Create follow-up record
        $followUp = [
            'company_id' => $sourceActivity->getCompany()?->getId(),
            'contact_id' => $sourceActivity->getContact()?->getId(),
            'source_activity_id' => $sourceActivity->getId(),
            'follow_up_date' => $followUpDate,
            'activity_type' => $activityType,
            'outcome' => $outcome,
            'note' => $customNote ?? $this->generateFollowUpNote($activityType, $outcome),
            'priority' => $this->determinePriority($activityType, $outcome),
            'status' => 'pending',
            'created_at' => new \DateTime(),
        ];
        
        // Store in activity notes (for now - could be separate table in future)
        $notes = $sourceActivity->getNotes() ?? '';
        $notes .= sprintf(
            "\n\n[Follow-up scheduled for %s: %s]",
            $followUpDate->format('Y-m-d'),
            $followUp['note']
        );
        $sourceActivity->setNotes($notes);
        
        $this->entityManager->flush();
        
        $this->logger->info('Follow-up scheduled', [
            'company' => $sourceActivity->getCompany()?->getName(),
            'date' => $followUpDate->format('Y-m-d'),
            'type' => $activityType,
        ]);
        
        return $followUp;
    }
    
    /**
     * Get all pending follow-ups for a user
     */
    public function getPendingFollowUps(?string $ownerRep = null, int $limit = 50): array
    {
        $followUps = [];
        $now = new \DateTime();
        
        // Get companies with scheduled activities
        $qb = $this->companyRepository->createQueryBuilder('c');
        
        if ($ownerRep) {
            // Resolve the rep to a User (matched by email or full name) and
            // restrict to activities owned by that user.
            $user = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
                ->where('u.email = :ownerRep')
                ->orWhere('LOWER(CONCAT(u.firstName, \' \', u.lastName)) = LOWER(:ownerRep)')
                ->setParameter('ownerRep', $ownerRep)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$user) {
                $this->logger->warning('FollowUpReminderService: owner rep not found', [
                    'owner_rep' => $ownerRep,
                ]);
                return [];
            }

            // Filter companies that have activities with notes containing
            // follow-up markers owned by the specified rep (via Activity join)
            $qb->innerJoin('App\Entity\Activity', 'a', 'WITH', 'a.company = c AND a.user = :user')
               ->where('a.notes LIKE :followUpMarker')
               ->setParameter('user', $user)
               ->setParameter('followUpMarker', '%[Follow-up scheduled for%')
               ->groupBy('c.id');
        }
        
        $companies = $qb->getQuery()->getResult();
        
        foreach ($companies as $company) {
            $activities = $this->activityRepository->findByCompanyWithLimit($company, 5);
            
            foreach ($activities as $activity) {
                $notes = $activity->getNotes() ?? '';
                
                // Check for follow-up markers
                if (preg_match('/\[Follow-up scheduled for (\d{4}-\d{2}-\d{2}): (.+?)\]/', $notes, $matches)) {
                    $followUpDate = new \DateTime($matches[1]);
                    $followUpNote = $matches[2];
                    
                    $status = 'pending';
                    if ($followUpDate < $now) {
                        $status = 'overdue';
                    }
                    
                    $followUps[] = [
                        'activity' => $activity,
                        'company' => $company,
                        'follow_up_date' => $followUpDate,
                        'note' => $followUpNote,
                        'status' => $status,
                        'days_until' => $followUpDate->diff($now)->days * ($followUpDate < $now ? -1 : 1),
                        'priority' => $this->determinePriorityFromStatus($status, $followUpDate->diff($now)->days),
                    ];
                }
            }
        }
        
        // Sort by date (overdue first, then upcoming)
        usort($followUps, function ($a, $b) {
            if ($a['status'] === 'overdue' && $b['status'] !== 'overdue') return -1;
            if ($a['status'] !== 'overdue' && $b['status'] === 'overdue') return 1;
            return $a['follow_up_date'] <=> $b['follow_up_date'];
        });
        
        return array_slice($followUps, 0, $limit);
    }
    
    /**
     * Get overdue follow-ups
     */
    public function getOverdueFollowUps(?string $ownerRep = null): array
    {
        $allFollowUps = $this->getPendingFollowUps($ownerRep, 100);
        
        return array_filter($allFollowUps, function ($fu) {
            return $fu['status'] === 'overdue';
        });
    }
    
    /**
     * Get today's follow-ups
     */
    public function getTodaysFollowUps(?string $ownerRep = null): array
    {
        $today = (new \DateTime())->setTime(0, 0, 0);
        $tomorrow = (clone $today)->modify('+1 day');
        
        $allFollowUps = $this->getPendingFollowUps($ownerRep, 100);
        
        return array_filter($allFollowUps, function ($fu) use ($today, $tomorrow) {
            $fuDate = $fu['follow_up_date'];
            return $fuDate >= $today && $fuDate < $tomorrow;
        });
    }
    
    /**
     * Mark follow-up as complete
     */
    public function completeFollowUp(Activity $activity, string $outcome = 'completed'): void
    {
        $notes = $activity->getNotes() ?? '';
        
        // Update follow-up marker
        $notes = preg_replace(
            '/\[Follow-up scheduled for \d{4}-\d{2}-\d{2}: .+?\]/',
            sprintf('[Follow-up completed on %s: %s]', (new \DateTime())->format('Y-m-d'), $outcome),
            $notes
        );
        
        $activity->setNotes($notes);
        $this->entityManager->flush();
    }
    
    /**
     * Reschedule a follow-up
     */
    public function rescheduleFollowUp(Activity $activity, \DateTimeInterface $newDate, ?string $reason = null): void
    {
        $notes = $activity->getNotes() ?? '';
        
        // Update follow-up marker (use callback to preserve captured group)
        $formattedDate = $newDate->format('Y-m-d');
        $reasonSuffix = $reason ? " (Reason: {$reason})" : '';
        $notes = preg_replace_callback(
            '/\[Follow-up scheduled for \d{4}-\d{2}-\d{2}: (.+?)\]/',
            function (array $matches) use ($formattedDate, $reasonSuffix) {
                return sprintf('[Follow-up rescheduled to %s: %s%s]', $formattedDate, $matches[1], $reasonSuffix);
            },
            $notes
        );
        
        $activity->setNotes($notes);
        $this->entityManager->flush();
    }
    
    /**
     * Get follow-up summary for dashboard
     */
    public function getFollowUpSummary(?string $ownerRep = null): array
    {
        $allFollowUps = $this->getPendingFollowUps($ownerRep, 200);
        
        $today = count($this->getTodaysFollowUps($ownerRep));
        $overdue = count(array_filter($allFollowUps, fn($fu) => $fu['status'] === 'overdue'));
        $upcoming = count($allFollowUps) - $overdue;
        
        // Group by priority
        $byPriority = [
            self::PRIORITY_URGENT => 0,
            self::PRIORITY_HIGH => 0,
            self::PRIORITY_MEDIUM => 0,
            self::PRIORITY_LOW => 0,
        ];
        
        foreach ($allFollowUps as $fu) {
            $priority = $fu['priority'] ?? self::PRIORITY_MEDIUM;
            $byPriority[$priority]++;
        }
        
        return [
            'total' => count($allFollowUps),
            'today' => $today,
            'overdue' => $overdue,
            'upcoming' => $upcoming,
            'by_priority' => $byPriority,
        ];
    }
    
    /**
     * Suggest next action based on activity history
     */
    public function suggestNextAction(Company $company): array
    {
        $activities = $this->activityRepository->findRecentByCompany($company, 60);
        
        if (empty($activities)) {
            return [
                'action' => 'email',
                'reason' => 'No recent activity - initiate contact',
                'template' => 'initial_outreach',
                'priority' => self::PRIORITY_HIGH,
            ];
        }
        
        // Analyze activity pattern
        $lastActivity = $activities[0];
        $lastType = strtolower($lastActivity->getType() ?? '');
        $daysSince = $lastActivity->getActivityDate() 
            ? (new \DateTime())->diff($lastActivity->getActivityDate())->days 
            : 999;
        
        // Determine logical next step
        $activityProgression = [
            'email' => 'call',
            'call' => 'meeting',
            'meeting' => 'proposal',
            'proposal_sent' => 'meeting',
            'demo' => 'proposal',
            'site_visit' => 'proposal',
        ];
        
        $suggestedAction = $activityProgression[$lastType] ?? 'email';
        
        // Adjust based on time elapsed
        if ($daysSince > 14) {
            $suggestedAction = 'email'; // Re-engage first
            $reason = 'More than 2 weeks since last contact - re-engage via email';
        } elseif ($daysSince > 7) {
            $reason = sprintf('7+ days since last %s - time to follow up', $lastType);
        } else {
            $reason = sprintf('Natural progression from %s', $lastType);
        }
        
        return [
            'action' => $suggestedAction,
            'reason' => $reason,
            'template' => $this->suggestTemplate($suggestedAction, $lastType),
            'priority' => $daysSince > 14 ? self::PRIORITY_HIGH : self::PRIORITY_MEDIUM,
        ];
    }
    
    // Private helpers
    
    private function getFollowUpDays(string $activityType, string $outcome): ?int
    {
        $rules = self::FOLLOWUP_RULES[$activityType] ?? self::FOLLOWUP_RULES['email'];
        return $rules[$outcome] ?? $rules['sent'] ?? 3;
    }
    
    private function generateFollowUpNote(string $activityType, string $outcome): string
    {
        return match ($activityType) {
            'email' => match ($outcome) {
                'sent' => 'Follow up on email - check for response',
                'no_response' => 'No response to email - try alternate approach',
                'opened' => 'Email was opened - strike while hot',
                'clicked' => 'Email link clicked - high engagement, follow up immediately',
                default => 'Email follow-up needed',
            },
            'call' => match ($outcome) {
                'connected' => 'Schedule follow-up call to continue discussion',
                'voicemail' => 'Left voicemail - try calling again',
                'no_answer' => 'No answer - try again',
                'callback_requested' => 'Callback requested - call back same day',
                default => 'Call follow-up needed',
            },
            'meeting' => match ($outcome) {
                'completed' => 'Send meeting summary and next steps',
                'no_show' => 'Reschedule meeting with contact',
                default => 'Meeting follow-up needed',
            },
            'proposal_sent' => match ($outcome) {
                'sent' => 'Check if proposal was received and reviewed',
                'reviewing' => 'Follow up on proposal review status',
                'questions' => 'Answer proposal questions promptly',
                default => 'Proposal follow-up needed',
            },
            'demo' => 'Send demo follow-up and gather feedback',
            'site_visit' => 'Send site visit summary and next steps',
            'rfq_received' => match ($outcome) {
                'received' => 'Process RFQ and send quote',
                'quoted' => 'Follow up on quote status',
                default => 'RFQ follow-up needed',
            },
            default => 'Follow-up needed',
        };
    }
    
    private function determinePriority(string $activityType, string $outcome): string
    {
        // High-value activities get higher priority
        if (in_array($activityType, ['rfq_received', 'proposal_sent', 'demo', 'site_visit'])) {
            return self::PRIORITY_HIGH;
        }
        
        // Engaged prospects get higher priority
        if (in_array($outcome, ['clicked', 'callback_requested', 'questions'])) {
            return self::PRIORITY_URGENT;
        }
        
        if (in_array($outcome, ['opened', 'connected', 'completed'])) {
            return self::PRIORITY_HIGH;
        }
        
        return self::PRIORITY_MEDIUM;
    }
    
    private function determinePriorityFromStatus(string $status, int $days): string
    {
        if ($status === 'overdue') {
            if ($days > 7) return self::PRIORITY_URGENT;
            if ($days > 3) return self::PRIORITY_HIGH;
            return self::PRIORITY_MEDIUM;
        }
        
        if ($days <= 1) return self::PRIORITY_HIGH;
        if ($days <= 3) return self::PRIORITY_MEDIUM;
        return self::PRIORITY_LOW;
    }
    
    private function suggestTemplate(string $action, string $lastAction): string
    {
        return match ($action) {
            'email' => match ($lastAction) {
                'email' => 'follow_up_email',
                'call' => 'call_summary_email',
                'meeting' => 'meeting_summary',
                default => 'general_check_in',
            },
            'call' => 'call_prep_notes',
            'meeting' => 'meeting_request',
            'proposal' => 'proposal_template',
            default => 'general_template',
        };
    }
}
