<?php

namespace App\Entity;

use App\Repository\LearnedCompetitorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dynamically Learned Competitor Entity
 * 
 * Stores competitors discovered through web scraping, allowing the 
 * competitor list to grow organically over time rather than being 
 * limited to a static list.
 * 
 * Discovery sources:
 * - Website content analysis (company pages, press releases)
 * - LinkedIn scraping (company mentions)
 * - Google Dork results (supplier lists, customer references)
 * - Manual additions by users
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
#[ORM\Entity(repositoryClass: LearnedCompetitorRepository::class)]
#[ORM\Table(name: 'learned_competitors')]
#[ORM\Index(name: 'idx_learned_comp_domain', columns: ['domain'])]
#[ORM\Index(name: 'idx_learned_comp_tier', columns: ['tier'])]
#[ORM\Index(name: 'idx_learned_comp_active', columns: ['active'])]
#[ORM\Index(name: 'idx_learned_comp_verified', columns: ['verified'])]
class LearnedCompetitor
{
    // Discovery source constants
    public const SOURCE_WEBSITE_SCRAPE = 'website_scrape';
    public const SOURCE_GOOGLE_DORK = 'google_dork';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CUSTOMER_REFERENCE = 'customer_reference';
    public const SOURCE_PRESS_RELEASE = 'press_release';
    public const SOURCE_APP_ADS_TXT = 'app_ads_txt';
    
    // Industry categories
    public const INDUSTRY_EMS = 'ems';           // Electronic Manufacturing Services
    public const INDUSTRY_PCBA = 'pcba';         // PCB Assembly
    public const INDUSTRY_SEMICONDUCTOR = 'semiconductor';
    public const INDUSTRY_TEST_EQUIPMENT = 'test_equipment';
    public const INDUSTRY_COMPONENTS = 'components';
    public const INDUSTRY_SOFTWARE = 'software';
    public const INDUSTRY_LOGISTICS = 'logistics';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // Protected (not private): Doctrine assigns the identifier via reflection
    // on hydration, so static analysis never sees an int assignment.
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $domain = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $tier = 3;

    #[ORM\Column(length: 50)]
    private string $industry = self::INDUSTRY_EMS;

    #[ORM\Column(length: 50)]
    private string $discoverySource = self::SOURCE_WEBSITE_SCRAPE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $discoveryContext = null;

    #[ORM\Column]
    private int $detectionCount = 1;

    #[ORM\Column]
    private int $confidenceScore = 50;

    #[ORM\Column]
    private bool $verified = false;

    #[ORM\Column]
    private bool $active = true;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $aliases = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $keywords = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $firstDetectedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $lastDetectedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $verifiedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $verifiedBy = null;

    public function __construct()
    {
        $this->firstDetectedAt = new \DateTime();
        $this->lastDetectedAt = new \DateTime();
        $this->aliases = [];
        $this->keywords = [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): static
    {
        $this->domain = strtolower(trim($domain));
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): static
    {
        $this->fullName = $fullName;
        return $this;
    }

    public function getTier(): int
    {
        return $this->tier;
    }

    public function setTier(int $tier): static
    {
        $this->tier = max(1, min(3, $tier));
        return $this;
    }

    public function getIndustry(): string
    {
        return $this->industry;
    }

    public function setIndustry(string $industry): static
    {
        $this->industry = $industry;
        return $this;
    }

    public function getDiscoverySource(): string
    {
        return $this->discoverySource;
    }

    public function setDiscoverySource(string $discoverySource): static
    {
        $this->discoverySource = $discoverySource;
        return $this;
    }

    public function getDiscoveryContext(): ?string
    {
        return $this->discoveryContext;
    }

    public function setDiscoveryContext(?string $discoveryContext): static
    {
        $this->discoveryContext = $discoveryContext;
        return $this;
    }

    public function getDetectionCount(): int
    {
        return $this->detectionCount;
    }

    public function setDetectionCount(int $detectionCount): static
    {
        $this->detectionCount = $detectionCount;
        return $this;
    }

    public function incrementDetectionCount(): static
    {
        $this->detectionCount++;
        $this->lastDetectedAt = new \DateTime();
        
        // Auto-increase confidence and tier as more detections occur
        $this->updateConfidenceFromDetections();
        
        return $this;
    }

    private function updateConfidenceFromDetections(): void
    {
        $this->confidenceScore = self::calculateConfidenceFromDetections($this->detectionCount);
        $this->tier = self::calculateTierFromDetections($this->detectionCount, $this->tier);
    }

    public static function calculateConfidenceFromDetections(int $detectionCount): int
    {
        return min(100, (int)(50 + 15 * log(max(1, $detectionCount + 1))));
    }

    public static function calculateTierFromDetections(int $detectionCount, int $currentTier): int
    {
        if ($detectionCount >= 50 && $currentTier === 2) {
            return 1;
        }
        if ($detectionCount >= 20 && $currentTier === 3) {
            return 2;
        }
        return $currentTier;
    }

    public function getConfidenceScore(): int
    {
        return $this->confidenceScore;
    }

    public function setConfidenceScore(int $confidenceScore): static
    {
        $this->confidenceScore = max(0, min(100, $confidenceScore));
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): static
    {
        $this->verified = $verified;
        if ($verified) {
            $this->verifiedAt = new \DateTime();
            $this->confidenceScore = 100;
        }
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    /**
     * @return list<string>
     */
    public function getAliases(): array
    {
        return $this->aliases ?? [];
    }

    /**
     * @param list<string>|null $aliases
     */
    public function setAliases(?array $aliases): static
    {
        $this->aliases = $aliases;
        return $this;
    }

    public function addAlias(string $alias): static
    {
        $alias = strtolower(trim($alias));
        if ($this->aliases === null) { $this->aliases = []; }
        if (!in_array($alias, $this->aliases)) {
            $this->aliases[] = $alias;
        }
        return $this;
    }

    /**
     * @return list<string>
     */
    public function getKeywords(): array
    {
        return $this->keywords ?? [];
    }

    /**
     * @param list<string>|null $keywords
     */
    public function setKeywords(?array $keywords): static
    {
        $this->keywords = $keywords;
        return $this;
    }

    public function addKeyword(string $keyword): static
    {
        $keyword = strtolower(trim($keyword));
        if ($this->keywords === null) { $this->keywords = []; }
        if (!in_array($keyword, $this->keywords)) {
            $this->keywords[] = $keyword;
        }
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata ?? [];
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getFirstDetectedAt(): ?\DateTimeInterface
    {
        return $this->firstDetectedAt;
    }

    public function setFirstDetectedAt(\DateTimeInterface $firstDetectedAt): static
    {
        $this->firstDetectedAt = $firstDetectedAt;
        return $this;
    }

    public function getLastDetectedAt(): ?\DateTimeInterface
    {
        return $this->lastDetectedAt;
    }

    public function setLastDetectedAt(\DateTimeInterface $lastDetectedAt): static
    {
        $this->lastDetectedAt = $lastDetectedAt;
        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeInterface
    {
        return $this->verifiedAt;
    }

    public function getVerifiedBy(): ?string
    {
        return $this->verifiedBy;
    }

    public function setVerifiedBy(?string $verifiedBy): static
    {
        $this->verifiedBy = $verifiedBy;
        return $this;
    }

    /**
     * Build regex pattern to match this competitor in text
     */
    public function buildMatchPattern(): string
    {
        $patterns = [
            preg_quote($this->domain ?? '', '/'),
            preg_quote(strtolower($this->name ?? ''), '/'),
        ];
        
        if ($this->fullName) {
            $patterns[] = preg_quote(strtolower($this->fullName), '/');
        }
        
        foreach ($this->aliases ?? [] as $alias) {
            $patterns[] = preg_quote($alias, '/');
        }
        
        return '/(' . implode('|', $patterns) . ')/i';
    }

    /**
     * Check if content matches this competitor
     */
    public function matchesContent(string $content): bool
    {
        return (bool)preg_match($this->buildMatchPattern(), strtolower($content));
    }
}
