<?php

namespace App\Entity;

use App\Repository\QuoteCustomerRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Durable record of a PUBLIC customer request (quote-at-quantity or
 * revision request) submitted through a live-quote token. This is the
 * customer-facing contract: the request MUST exist in the database before
 * the "submitted" response is returned — a log line is not data.
 */
#[ORM\Entity(repositoryClass: QuoteCustomerRequestRepository::class)]
#[ORM\Table(name: 'quote_customer_requests')]
#[ORM\Index(name: 'idx_qcr_quote', columns: ['quote_id'])]
#[ORM\Index(name: 'idx_qcr_status', columns: ['status'])]
class QuoteCustomerRequest
{
    public const STATUS_NEW = 'new';
    public const STATUS_HANDLED = 'handled';

    public const TYPE_QUANTITY_REQUEST = 'quantity_request';
    public const TYPE_REVISION_REQUEST = 'revision_request';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Quote $quote = null;

    #[ORM\Column(length: 40)]
    private string $requestType = self::TYPE_QUANTITY_REQUEST;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $requestedQuantity = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $estimatedTotal = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $customerNotes = null;

    /** SHA-256 of the access token used — provenance WITHOUT storing the token. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenFingerprint = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $requestIp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $handledAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuote(): ?Quote
    {
        return $this->quote;
    }

    public function setQuote(?Quote $quote): self
    {
        $this->quote = $quote;

        return $this;
    }

    public function getRequestType(): string
    {
        return $this->requestType;
    }

    public function setRequestType(string $requestType): self
    {
        $this->requestType = $requestType;

        return $this;
    }

    public function getRequestedQuantity(): ?int
    {
        return $this->requestedQuantity;
    }

    public function setRequestedQuantity(?int $requestedQuantity): self
    {
        $this->requestedQuantity = $requestedQuantity;

        return $this;
    }

    public function getEstimatedTotal(): ?string
    {
        return $this->estimatedTotal;
    }

    public function setEstimatedTotal(?string $estimatedTotal): self
    {
        $this->estimatedTotal = $estimatedTotal;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getCustomerNotes(): ?string
    {
        return $this->customerNotes;
    }

    public function setCustomerNotes(?string $customerNotes): self
    {
        $this->customerNotes = $customerNotes;

        return $this;
    }

    public function getTokenFingerprint(): ?string
    {
        return $this->tokenFingerprint;
    }

    public function setTokenFingerprint(?string $tokenFingerprint): self
    {
        $this->tokenFingerprint = $tokenFingerprint;

        return $this;
    }

    public function getRequestIp(): ?string
    {
        return $this->requestIp;
    }

    public function setRequestIp(?string $requestIp): self
    {
        $this->requestIp = $requestIp;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function markHandled(): self
    {
        $this->status = self::STATUS_HANDLED;
        $this->handledAt = new \DateTime();

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getHandledAt(): ?\DateTimeInterface
    {
        return $this->handledAt;
    }
}
