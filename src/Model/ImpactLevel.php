<?php

namespace App\Model;

enum ImpactLevel: string
{
    case NONE = 'none';
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    public function getEmoji(): string
    {
        return match($this) {
            self::NONE => '✅',
            self::LOW => '🟡',
            self::MEDIUM => '🟠',
            self::HIGH => '🔴',
            self::CRITICAL => '🚨',
        };
    }

    public function getNumericValue(): int
    {
        return match($this) {
            self::NONE => 0,
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        };
    }

    public function getDisplayName(): string
    {
        return match($this) {
            self::NONE => 'No Impact',
            self::LOW => 'Low Impact',
            self::MEDIUM => 'Medium Impact',
            self::HIGH => 'High Impact',
            self::CRITICAL => 'Critical Impact',
        };
    }
}