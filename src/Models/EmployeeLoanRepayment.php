<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Models;

use Centrex\Payroll\Concerns\AddTablePrefix;
use Centrex\Payroll\Enums\RepaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLoanRepayment extends Model
{
    use AddTablePrefix;

    #[\Override]
    protected function getTableSuffix(): string
    {
        return 'employee_loan_repayments';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('payroll.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'employee_loan_id', 'payroll_entry_id',
        'amount', 'method', 'repaid_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount'    => 'decimal:2',
        'method'    => RepaymentMethod::class,
        'repaid_at' => 'date',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class, 'employee_loan_id');
    }

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }
}
