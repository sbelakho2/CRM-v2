<?php

namespace App\Entity;

use App\Repository\CompetitorDetectionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Competitor Detection
 * 
 * Caches detected competitors for "Sniper" targeting mode.
 * Identifies leads using competitor products for higher-value outreach.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
#[ORM\Entity(repositoryClass: CompetitorDetectionRepository::class)]
#[ORM\Table(name: 'competitor_detections')]
#[ORM\UniqueConstraint(name: 'unique_lead_competitor', columns: ['lead_id', 'competitor_domain'])]
#[ORM\Index(name: 'idx_competitor_tier', columns: ['competitor_tier'])]
#[ORM\Index(name: 'idx_competitor_domain', columns: ['competitor_domain'])]
class CompetitorDetection
{
    // Tier 1: Highest priority competitors (strongest signal)
    public const TIER_1_COMPETITORS = [
        'ironsource.com' => 'IronSource',
        'unity.com' => 'Unity Ads',
        'applovin.com' => 'AppLovin MAX',
    ];

    // Tier 2: Medium priority competitors
    public const TIER_2_COMPETITORS = [
        'mintegral.com' => 'Mintegral',
        'vungle.com' => 'Vungle',
        'chartboost.com' => 'Chartboost',
        'fyber.com' => 'Fyber',
        'inmobi.com' => 'InMobi',
    ];

    // Tier 3: Lower priority competitors
    public const TIER_3_COMPETITORS = [
        'adcolony.com' => 'AdColony',
        'tapjoy.com' => 'Tapjoy',
        'startapp.com' => 'StartApp',
        'ogury.com' => 'Ogury',
    ];

    // Score boosts per tier
    public const TIER_SCORE_BOOSTS = [
        1 => 45,
        2 => 30,
        3 => 20,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lead $lead = null;

    #[ORM\Column(length: 255)]
    private ?string $competitorDomain = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $competitorName = null;

    #[ORM\Column(type: 'integer', options: ['default' => 3])]
    private int $competitorTier = 3;

    #[ORM\Column(length: 50, options: ['default' => 'website_analysis'])]
    private string $detectedIn = 'website_analysis'; // 'website_analysis', 'app_ads_txt', 'sdk_scan', 'manual'

    #[ORM\Column(type: 'integer', options: ['default' => 100])]
    private int $detectionConfidence = 100;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): self
    {
        $this->lead = $lead;
        return $this;
    }

    public function getCompetitorDomain(): ?string
    {
        return $this->competitorDomain;
    }

    public function setCompetitorDomain(string $competitorDomain): self
    {
        $this->competitorDomain = $competitorDomain;
        return $this;
    }

    public function getCompetitorName(): ?string
    {
        return $this->competitorName;
    }

    public function setCompetitorName(?string $competitorName): self
    {
        $this->competitorName = $competitorName;
        return $this;
    }

    public function getCompetitorTier(): int
    {
        return $this->competitorTier;
    }

    public function setCompetitorTier(int $competitorTier): self
    {
        $this->competitorTier = $competitorTier;
        return $this;
    }

    public function getDetectedIn(): string
    {
        return $this->detectedIn;
    }

    public function setDetectedIn(string $detectedIn): self
    {
        $this->detectedIn = $detectedIn;
        return $this;
    }

    public function getDetectionConfidence(): int
    {
        return $this->detectionConfidence;
    }

    public function setDetectionConfidence(int $detectionConfidence): self
    {
        $this->detectionConfidence = $detectionConfidence;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * Get the score boost for this competitor detection
     */
    public function getScoreBoost(): int
    {
        return self::TIER_SCORE_BOOSTS[$this->competitorTier] ?? 20;
    }

    /**
     * Detect competitor tier from domain
     */
    public static function detectTierFromDomain(string $domain): int
    {
        $domain = strtolower(trim($domain));
        
        if (isset(self::TIER_1_COMPETITORS[$domain])) {
            return 1;
        }
        if (isset(self::TIER_2_COMPETITORS[$domain])) {
            return 2;
        }
        if (isset(self::TIER_3_COMPETITORS[$domain])) {
            return 3;
        }
        
        return 3; // Default to lowest tier
    }

    /**
     * Get competitor name from domain
     */
    public static function getNameFromDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        
        return self::TIER_1_COMPETITORS[$domain]
            ?? self::TIER_2_COMPETITORS[$domain]
            ?? self::TIER_3_COMPETITORS[$domain]
            ?? null;
    }

    /**
     * Get all known competitor domains
     */
    public static function getAllCompetitorDomains(): array
    {
        return array_merge(
            array_keys(self::TIER_1_COMPETITORS),
            array_keys(self::TIER_2_COMPETITORS),
            array_keys(self::TIER_3_COMPETITORS)
        );
    }
}
