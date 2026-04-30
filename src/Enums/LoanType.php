<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Enums;

enum LoanType: string
{
    case Loan    = 'loan';
    case Advance = 'advance';

    public function label(): string
    {
        return match ($this) {
            self::Loan    => 'Loan',
            self::Advance => 'Salary Advance',
        };
    }
}
