<?php

namespace App\Entity;

use App\Repository\CompetitorDetectionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompetitorDetectionRepository::class)]
#[ORM\Table(name: "competitor_detection")]
#[ORM\Index(name: "idx_competitor_domain", columns: ["competitor_domain"])]
class CompetitorDetection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Lead::class, inversedBy: "competitorDetections")]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private ?Lead $lead = null;

    #[ORM\Column(type: "string", length: 255)]
    private string $competitorDomain = '';

    #[ORM\Column(type: "string", length: 255)]
    private string $competitorName = '';

    #[ORM\Column(type: "smallint")]
    private int $competitorTier = 3;

    #[ORM\Column(type: "string", length: 100)]
    private string $detectedIn = 'website_analysis';

    #[ORM\Column(type: "smallint")]
    private int $detectionConfidence = 100;

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

    public function getCompetitorDomain(): string
    {
        return $this->competitorDomain;
    }

    public function setCompetitorDomain(string $competitorDomain): self
    {
        $this->competitorDomain = $competitorDomain;
        return $this;
    }

    public function getCompetitorName(): string
    {
        return $this->competitorName;
    }

    public function setCompetitorName(string $competitorName): self
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
}
