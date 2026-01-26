<?php

namespace App\Entity;

use App\Repository\BayesTrainingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bayes Training Word Frequency
 * 
 * Stores word frequencies per classification for Naive Bayes model.
 * Updated from human-reviewed email classifications to enable learning.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
#[ORM\Entity(repositoryClass: BayesTrainingRepository::class)]
#[ORM\Table(name: 'sa_bayes_training')]
#[ORM\UniqueConstraint(name: 'unique_word_class', columns: ['word', 'classification'])]
#[ORM\Index(name: 'idx_bayes_classification', columns: ['classification'])]
#[ORM\Index(name: 'idx_bayes_word', columns: ['word'])]
class BayesTraining
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $word = null;

    #[ORM\Column(length: 30)]
    private ?string $classification = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $frequency = 1;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $lastUpdated = null;

    public function __construct()
    {
        $this->lastUpdated = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWord(): ?string
    {
        return $this->word;
    }

    public function setWord(string $word): self
    {
        $this->word = strtolower($word);
        return $this;
    }

    public function getClassification(): ?string
    {
        return $this->classification;
    }

    public function setClassification(string $classification): self
    {
        $this->classification = $classification;
        return $this;
    }

    public function getFrequency(): int
    {
        return $this->frequency;
    }

    public function setFrequency(int $frequency): self
    {
        $this->frequency = max(1, $frequency);
        $this->lastUpdated = new \DateTime();
        return $this;
    }

    public function incrementFrequency(int $amount = 1): self
    {
        $this->frequency += $amount;
        $this->lastUpdated = new \DateTime();
        return $this;
    }

    public function getLastUpdated(): ?\DateTimeInterface
    {
        return $this->lastUpdated;
    }
}
