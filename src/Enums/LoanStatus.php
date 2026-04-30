<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Enums;

enum LoanStatus: string
{
    case Pending   = 'pending';
    case Active    = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Active    => 'Active',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending   => in_array($target, [self::Active, self::Cancelled], true),
            self::Active    => in_array($target, [self::Completed, self::Cancelled], true),
            self::Completed => false,
            self::Cancelled => false,
        };
    }
}
