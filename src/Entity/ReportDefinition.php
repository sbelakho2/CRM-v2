<?php

namespace App\Entity;

use App\Repository\ReportDefinitionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity(repositoryClass: ReportDefinitionRepository::class)]
#[ORM\Table(name: 'report_definitions')]
#[ORM\HasLifecycleCallbacks]
class ReportDefinition
{
    // Report Types
    public const TYPE_TABLE = 'table';
    public const TYPE_CHART_BAR = 'chart_bar';
    public const TYPE_CHART_LINE = 'chart_line';
    public const TYPE_CHART_PIE = 'chart_pie';
    public const TYPE_CHART_DOUGHNUT = 'chart_doughnut';
    public const TYPE_SUMMARY = 'summary';
    public const TYPE_KPI = 'kpi';
    
    // Data Sources (entities that can be reported on)
    public const SOURCE_COMPANY = 'company';
    public const SOURCE_CONTACT = 'contact';
    public const SOURCE_LEAD = 'lead';
    public const SOURCE_RFQ = 'rfq';
    public const SOURCE_QUOTE = 'quote';
    public const SOURCE_TASK = 'task';
    public const SOURCE_CALENDAR = 'calendar';
    public const SOURCE_EMAIL_CAMPAIGN = 'email_campaign';
    public const SOURCE_EMAIL_SEND = 'email_send';
    
    // Aggregation types
    public const AGG_COUNT = 'count';
    public const AGG_SUM = 'sum';
    public const AGG_AVG = 'avg';
    public const AGG_MIN = 'min';
    public const AGG_MAX = 'max';
    
    // Date range presets
    public const RANGE_TODAY = 'today';
    public const RANGE_YESTERDAY = 'yesterday';
    public const RANGE_THIS_WEEK = 'this_week';
    public const RANGE_LAST_WEEK = 'last_week';
    public const RANGE_THIS_MONTH = 'this_month';
    public const RANGE_LAST_MONTH = 'last_month';
    public const RANGE_THIS_QUARTER = 'this_quarter';
    public const RANGE_LAST_QUARTER = 'last_quarter';
    public const RANGE_THIS_YEAR = 'this_year';
    public const RANGE_LAST_YEAR = 'last_year';
    public const RANGE_CUSTOM = 'custom';
    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    
    #[ORM\Column(length: 255)]
    private ?string $name = null;
    
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;
    
    #[ORM\Column(length: 50)]
    private ?string $reportType = self::TYPE_TABLE;
    
    #[ORM\Column(length: 50)]
    private ?string $dataSource = null;
    
    #[ORM\Column(type: Types::JSON)]
    private array $columns = [];
    
    #[ORM\Column(type: Types::JSON)]
    private array $filters = [];
    
    #[ORM\Column(type: Types::JSON)]
    private array $groupBy = [];
    
    #[ORM\Column(type: Types::JSON)]
    private array $orderBy = [];
    
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $chartConfig = null;
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $dateRangePreset = null;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $dateField = null;
    
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $customDateStart = null;
    
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $customDateEnd = null;
    
    #[ORM\Column(nullable: true)]
    private ?int $recordLimit = null;
    
    #[ORM\Column]
    private bool $isPublic = false;
    
    #[ORM\Column]
    private bool $isFavorite = false;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;
    
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $accessRoles = null;
    
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $scheduledDelivery = null;
    
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;
    
    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;
    
    #[ORM\Column]
    private ?DateTimeImmutable $updatedAt = null;
    
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastRunAt = null;
    
    #[ORM\Column(nullable: true)]
    private ?int $runCount = 0;
    
    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }
    
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
    
    public function getId(): ?int
    {
        return $this->id;
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
    
    public function getDescription(): ?string
    {
        return $this->description;
    }
    
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }
    
    public function getReportType(): ?string
    {
        return $this->reportType;
    }
    
    public function setReportType(string $reportType): static
    {
        $this->reportType = $reportType;
        return $this;
    }
    
    public function getDataSource(): ?string
    {
        return $this->dataSource;
    }
    
    public function setDataSource(string $dataSource): static
    {
        $this->dataSource = $dataSource;
        return $this;
    }
    
    public function getColumns(): array
    {
        return $this->columns;
    }
    
    public function setColumns(array $columns): static
    {
        $this->columns = $columns;
        return $this;
    }
    
    public function getFilters(): array
    {
        return $this->filters;
    }
    
    public function setFilters(array $filters): static
    {
        $this->filters = $filters;
        return $this;
    }
    
    public function getGroupBy(): array
    {
        return $this->groupBy;
    }
    
    public function setGroupBy(array $groupBy): static
    {
        $this->groupBy = $groupBy;
        return $this;
    }
    
    public function getOrderBy(): array
    {
        return $this->orderBy;
    }
    
    public function setOrderBy(array $orderBy): static
    {
        $this->orderBy = $orderBy;
        return $this;
    }
    
    public function getChartConfig(): ?array
    {
        return $this->chartConfig;
    }
    
    public function setChartConfig(?array $chartConfig): static
    {
        $this->chartConfig = $chartConfig;
        return $this;
    }
    
    public function getDateRangePreset(): ?string
    {
        return $this->dateRangePreset;
    }
    
    public function setDateRangePreset(?string $dateRangePreset): static
    {
        $this->dateRangePreset = $dateRangePreset;
        return $this;
    }
    
    public function getDateField(): ?string
    {
        return $this->dateField;
    }
    
    public function setDateField(?string $dateField): static
    {
        $this->dateField = $dateField;
        return $this;
    }
    
    public function getCustomDateStart(): ?DateTimeImmutable
    {
        return $this->customDateStart;
    }
    
    public function setCustomDateStart(?DateTimeImmutable $customDateStart): static
    {
        $this->customDateStart = $customDateStart;
        return $this;
    }
    
    public function getCustomDateEnd(): ?DateTimeImmutable
    {
        return $this->customDateEnd;
    }
    
    public function setCustomDateEnd(?DateTimeImmutable $customDateEnd): static
    {
        $this->customDateEnd = $customDateEnd;
        return $this;
    }
    
    public function getRecordLimit(): ?int
    {
        return $this->recordLimit;
    }
    
    public function setRecordLimit(?int $recordLimit): static
    {
        $this->recordLimit = $recordLimit;
        return $this;
    }
    
    public function isPublic(): bool
    {
        return $this->isPublic;
    }
    
    public function setIsPublic(bool $isPublic): static
    {
        $this->isPublic = $isPublic;
        return $this;
    }
    
    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }
    
    public function setIsFavorite(bool $isFavorite): static
    {
        $this->isFavorite = $isFavorite;
        return $this;
    }
    
    public function getCategory(): ?string
    {
        return $this->category;
    }
    
    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }
    
    public function getAccessRoles(): ?array
    {
        return $this->accessRoles;
    }
    
    public function setAccessRoles(?array $accessRoles): static
    {
        $this->accessRoles = $accessRoles;
        return $this;
    }
    
    public function getScheduledDelivery(): ?array
    {
        return $this->scheduledDelivery;
    }
    
    public function setScheduledDelivery(?array $scheduledDelivery): static
    {
        $this->scheduledDelivery = $scheduledDelivery;
        return $this;
    }
    
    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }
    
    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }
    
    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
    
    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
    
    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
    
    public function setUpdatedAt(DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
    
    public function getLastRunAt(): ?DateTimeImmutable
    {
        return $this->lastRunAt;
    }
    
    public function setLastRunAt(?DateTimeImmutable $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;
        return $this;
    }
    
    public function getRunCount(): ?int
    {
        return $this->runCount;
    }
    
    public function setRunCount(?int $runCount): static
    {
        $this->runCount = $runCount;
        return $this;
    }
    
    public function incrementRunCount(): static
    {
        $this->runCount = ($this->runCount ?? 0) + 1;
        $this->lastRunAt = new DateTimeImmutable();
        return $this;
    }
    
    // Helper methods
    
    public function isChartReport(): bool
    {
        return in_array($this->reportType, [
            self::TYPE_CHART_BAR,
            self::TYPE_CHART_LINE,
            self::TYPE_CHART_PIE,
            self::TYPE_CHART_DOUGHNUT
        ]);
    }
    
    public function isTableReport(): bool
    {
        return $this->reportType === self::TYPE_TABLE;
    }
    
    public function getReportTypeIcon(): string
    {
        return match($this->reportType) {
            self::TYPE_TABLE => 'table',
            self::TYPE_CHART_BAR => 'chart-bar',
            self::TYPE_CHART_LINE => 'chart-line',
            self::TYPE_CHART_PIE => 'chart-pie',
            self::TYPE_CHART_DOUGHNUT => 'circle-notch',
            self::TYPE_SUMMARY => 'clipboard-list',
            self::TYPE_KPI => 'tachometer-alt',
            default => 'file-alt'
        };
    }
    
    public function getDataSourceIcon(): string
    {
        return match($this->dataSource) {
            self::SOURCE_COMPANY => 'building',
            self::SOURCE_CONTACT => 'user',
            self::SOURCE_LEAD => 'bullseye',
            self::SOURCE_RFQ => 'file-invoice',
            self::SOURCE_QUOTE => 'file-invoice-dollar',
            self::SOURCE_TASK => 'tasks',
            self::SOURCE_CALENDAR => 'calendar-alt',
            self::SOURCE_EMAIL_CAMPAIGN => 'envelope',
            self::SOURCE_EMAIL_SEND => 'paper-plane',
            default => 'database'
        };
    }
    
    public static function getReportTypes(): array
    {
        return [
            'Table' => self::TYPE_TABLE,
            'Bar Chart' => self::TYPE_CHART_BAR,
            'Line Chart' => self::TYPE_CHART_LINE,
            'Pie Chart' => self::TYPE_CHART_PIE,
            'Doughnut Chart' => self::TYPE_CHART_DOUGHNUT,
            'Summary' => self::TYPE_SUMMARY,
            'KPI Dashboard' => self::TYPE_KPI,
        ];
    }
    
    public static function getDataSources(): array
    {
        return [
            'Companies' => self::SOURCE_COMPANY,
            'Contacts' => self::SOURCE_CONTACT,
            'Leads' => self::SOURCE_LEAD,
            'RFQs' => self::SOURCE_RFQ,
            'Quotes' => self::SOURCE_QUOTE,
            'Tasks' => self::SOURCE_TASK,
            'Calendar Events' => self::SOURCE_CALENDAR,
            'Email Campaigns' => self::SOURCE_EMAIL_CAMPAIGN,
            'Email Sends' => self::SOURCE_EMAIL_SEND,
        ];
    }
    
    public static function getAggregationTypes(): array
    {
        return [
            'Count' => self::AGG_COUNT,
            'Sum' => self::AGG_SUM,
            'Average' => self::AGG_AVG,
            'Minimum' => self::AGG_MIN,
            'Maximum' => self::AGG_MAX,
        ];
    }
    
    public static function getDateRangePresets(): array
    {
        return [
            'Today' => self::RANGE_TODAY,
            'Yesterday' => self::RANGE_YESTERDAY,
            'This Week' => self::RANGE_THIS_WEEK,
            'Last Week' => self::RANGE_LAST_WEEK,
            'This Month' => self::RANGE_THIS_MONTH,
            'Last Month' => self::RANGE_LAST_MONTH,
            'This Quarter' => self::RANGE_THIS_QUARTER,
            'Last Quarter' => self::RANGE_LAST_QUARTER,
            'This Year' => self::RANGE_THIS_YEAR,
            'Last Year' => self::RANGE_LAST_YEAR,
            'Custom Range' => self::RANGE_CUSTOM,
        ];
    }
    
    public function canUserAccess(User $user): bool
    {
        return self::canUserAccessStatic($this, $user);
    }

    public static function canUserAccessStatic(self $report, User $user): bool
    {
        // Creator always has access
        if ($report->createdBy && $report->createdBy->getId() === $user->getId()) {
            return true;
        }

        // Public reports are accessible to all
        if ($report->isPublic) {
            return true;
        }

        // Check role-based access
        if ($report->accessRoles) {
            foreach ($report->accessRoles as $role) {
                if (in_array($role, $user->getRoles())) {
                    return true;
                }
            }
        }

        return false;
    }
}
