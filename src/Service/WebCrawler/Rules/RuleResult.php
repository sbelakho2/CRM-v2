<?php

namespace App\Service\WebCrawler\Rules;

/**
 * Individual rule result — traces which rule fired and why.
 */
final class RuleResult
{
    public function __construct(
        public readonly string $ruleName,
        public readonly string $ruleType,       // 'competitor', 'wrong_type', 'blocked_domain', 'junk_name', 'positive'
        public readonly string $action,          // 'reject', 'boost', 'flag'
        public readonly int    $weight,
        public readonly string $matchedText,
        public readonly ?string $description = null,
    ) {
    }

    /** @return array{rule: string, type: string, action: string, weight: int, matched: string, description: string|null} */
    public function toArray(): array
    {
        return [
            'rule'        => $this->ruleName,
            'type'        => $this->ruleType,
            'action'      => $this->action,
            'weight'      => $this->weight,
            'matched'     => $this->matchedText,
            'description' => $this->description,
        ];
    }
}
