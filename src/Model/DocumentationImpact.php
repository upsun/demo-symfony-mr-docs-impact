<?php

namespace App\Model;

final readonly class DocumentationImpact
{
    public function __construct(
        public ImpactLevel $level,
        public bool $required,
        public array $impactedAreas,
        public array $reasons,
        public array $suggestions,
    ) {}

    public function toArray(): array
    {
        return [
            'level' => $this->level->value,
            'required' => $this->required,
            'impacted_areas' => $this->impactedAreas,
            'reasons' => $this->reasons,
            'suggestions' => $this->suggestions,
            'display_name' => $this->level->getDisplayName(),
            'emoji' => $this->level->getEmoji(),
        ];
    }

    public function shouldComment(): bool
    {
        return $this->required || $this->level->getNumericValue() >= ImpactLevel::MEDIUM->getNumericValue();
    }

    public static function createSafeDefault(string $reason = 'AI analysis failed - manual review recommended'): self
    {
        return new self(
            level: ImpactLevel::MEDIUM,
            required: true,
            impactedAreas: ['unknown'],
            reasons: [$reason],
            suggestions: ['Please review this change manually to determine documentation requirements.'],
        );
    }
}