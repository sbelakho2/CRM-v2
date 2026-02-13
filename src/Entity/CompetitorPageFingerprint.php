<?php

namespace App\Entity;

use App\Repository\CompetitorPageFingerprintRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompetitorPageFingerprintRepository::class)]
#[ORM\Table(name: 'competitor_page_fingerprints')]
#[ORM\UniqueConstraint(name: 'uk_cpf_comp_url', columns: ['competitor_id', 'url_hash'])]
#[ORM\Index(name: 'idx_cpf_competitor', columns: ['competitor_id'])]
#[ORM\Index(name: 'idx_cpf_urlhash', columns: ['url_hash'])]
#[ORM\Index(name: 'idx_cpf_checked', columns: ['last_checked_at'])]
class CompetitorPageFingerprint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Competitor::class, inversedBy: 'pageFingerprints')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Competitor $competitor = null;

    #[ORM\Column(length: 2048)]
    private ?string $url = null;

    #[ORM\Column(length: 64)]
    private ?string $urlHash = null;

    #[ORM\Column(length: 64)]
    private ?string $contentHash = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastModified = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $etag = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $extractedFacts = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $pageType = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $lastCheckedAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $firstSeenAt = null;

    public function __construct()
    {
        $now = new \DateTime();
        $this->lastCheckedAt = $now;
        $this->firstSeenAt = $now;
    }

    public function getId(): ?int { return $this->id; }

    public function getCompetitor(): ?Competitor { return $this->competitor; }
    public function setCompetitor(?Competitor $c): self { $this->competitor = $c; return $this; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(string $url): self
    {
        $this->url = $url;
        $this->urlHash = hash('sha256', $url);
        return $this;
    }

    public function getUrlHash(): ?string { return $this->urlHash; }

    public function getContentHash(): ?string { return $this->contentHash; }
    public function setContentHash(string $hash): self { $this->contentHash = $hash; return $this; }

    public function getLastModified(): ?string { return $this->lastModified; }
    public function setLastModified(?string $lm): self { $this->lastModified = $lm; return $this; }

    public function getEtag(): ?string { return $this->etag; }
    public function setEtag(?string $etag): self { $this->etag = $etag; return $this; }

    public function getExtractedFacts(): ?array { return $this->extractedFacts; }
    public function setExtractedFacts(?array $facts): self { $this->extractedFacts = $facts; return $this; }

    public function getPageType(): ?string { return $this->pageType; }
    public function setPageType(?string $type): self { $this->pageType = $type; return $this; }

    public function getLastCheckedAt(): ?\DateTimeInterface { return $this->lastCheckedAt; }
    public function setLastCheckedAt(\DateTimeInterface $dt): self { $this->lastCheckedAt = $dt; return $this; }

    public function getFirstSeenAt(): ?\DateTimeInterface { return $this->firstSeenAt; }

    /** Check if content changed since last fingerprint */
    public function hasChanged(string $newContentHash): bool
    {
        return $this->contentHash !== $newContentHash;
    }
}
