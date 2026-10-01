<?php

namespace App\Repository;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailCampaign>
 */
class EmailCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailCampaign::class);
    }

    /**
     * Compute send metrics for a set of campaigns in a single aggregate query.
     *
     * Replaces the N+1 pattern of calling EmailCampaignService::getCampaignMetrics()
     * once per campaign. Returns the same metric shape that getCampaignMetrics()
     * produces (counts + rates), keyed by campaign id.
     *
     * @param int[] $campaignIds
     * @return array<int, array{total_sent: int, opened: int, clicked: int, replied: int, bounced: int, open_rate: float, click_rate: float, reply_rate: float, bounce_rate: float}>
     */
    public /**
 * @param array<string|int, mixed> $campaignIds
 */
function findWithSendCounts(array $campaignIds): array
    {
        if (empty($campaignIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id AS campaign_id')
            ->addSelect('COALESCE(SUM(CASE WHEN s.status IN (:deliveredStatuses) THEN 1 ELSE 0 END), 0) AS total_sent')
            ->addSelect('COALESCE(SUM(CASE WHEN s.opened = true THEN 1 ELSE 0 END), 0) AS opened')
            ->addSelect('COALESCE(SUM(CASE WHEN s.clicked = true THEN 1 ELSE 0 END), 0) AS clicked')
            ->addSelect('COALESCE(SUM(CASE WHEN s.replied = true THEN 1 ELSE 0 END), 0) AS replied')
            ->addSelect('COALESCE(SUM(CASE WHEN s.bounced = true THEN 1 ELSE 0 END), 0) AS bounced')
            ->leftJoin('c.emailSends', 's')
            ->where('c.id IN (:campaignIds)')
            ->setParameter('campaignIds', $campaignIds)
            ->setParameter('deliveredStatuses', [EmailSend::STATUS_SENT, EmailSend::STATUS_BOUNCED])
            ->groupBy('c.id')
            ->getQuery()
            ->getResult();

        $metrics = [];
        foreach ($rows as $row) {
            $total = (int) $row['total_sent'];
            $opened = (int) $row['opened'];
            $clicked = (int) $row['clicked'];
            $replied = (int) $row['replied'];
            $bounced = (int) $row['bounced'];

            $metrics[(int) $row['campaign_id']] = [
                'total_sent' => $total,
                'opened' => $opened,
                'clicked' => $clicked,
                'replied' => $replied,
                'bounced' => $bounced,
                'open_rate' => $total > 0 ? ($opened / $total) * 100 : 0,
                'click_rate' => $total > 0 ? ($clicked / $total) * 100 : 0,
                'reply_rate' => $total > 0 ? ($replied / $total) * 100 : 0,
                'bounce_rate' => $total > 0 ? ($bounced / $total) * 100 : 0,
            ];
        }

        return $metrics;
    }
}
