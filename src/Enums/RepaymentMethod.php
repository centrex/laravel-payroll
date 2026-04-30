<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Enums;

enum RepaymentMethod: string
{
    case SalaryDeduction = 'salary_deduction';
    case Cash            = 'cash';
    case BankTransfer    = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::SalaryDeduction => 'Salary Deduction',
            self::Cash            => 'Cash',
            self::BankTransfer    => 'Bank Transfer',
        };
    }
}
