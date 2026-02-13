<?php

namespace App\Entity;

use App\Repository\CompetitorBlockIntelRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompetitorBlockIntelRepository::class)]
#[ORM\Table(name: 'competitor_block_intel')]
#[ORM\Index(name: 'idx_cbi_source', columns: ['source_competitor_id'])]
#[ORM\Index(name: 'idx_cbi_synced', columns: ['synced_to_leadcrawler'])]
class CompetitorBlockIntel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Competitor::class)]
    #[ORM\JoinColumn(name: 'source_competitor_id', nullable: true, onDelete: 'SET NULL')]
    private ?Competitor $sourceCompetitor = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $blockedDomainsAdd = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $competitorPhrasesAdd = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $embeddingExamplesAdd = [];

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $syncedToLeadcrawler = false;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $generatedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $syncedAt = null;

    public function __construct()
    {
        $this->generatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getSourceCompetitor(): ?Competitor { return $this->sourceCompetitor; }
    public function setSourceCompetitor(?Competitor $c): self { $this->sourceCompetitor = $c; return $this; }

    public function getBlockedDomainsAdd(): array { return $this->blockedDomainsAdd ?? []; }
    public function setBlockedDomainsAdd(?array $domains): self { $this->blockedDomainsAdd = $domains; return $this; }

    public function getCompetitorPhrasesAdd(): array { return $this->competitorPhrasesAdd ?? []; }
    public function setCompetitorPhrasesAdd(?array $phrases): self { $this->competitorPhrasesAdd = $phrases; return $this; }

    public function getEmbeddingExamplesAdd(): array { return $this->embeddingExamplesAdd ?? []; }
    public function setEmbeddingExamplesAdd(?array $examples): self { $this->embeddingExamplesAdd = $examples; return $this; }

    public function isSyncedToLeadcrawler(): bool { return $this->syncedToLeadcrawler; }
    public function setSyncedToLeadcrawler(bool $synced): self { $this->syncedToLeadcrawler = $synced; return $this; }

    public function getGeneratedAt(): ?\DateTimeInterface { return $this->generatedAt; }
    public function setGeneratedAt(\DateTimeInterface $dt): self { $this->generatedAt = $dt; return $this; }

    public function getSyncedAt(): ?\DateTimeInterface { return $this->syncedAt; }
    public function setSyncedAt(?\DateTimeInterface $dt): self { $this->syncedAt = $dt; return $this; }
}
