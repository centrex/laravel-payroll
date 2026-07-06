<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Models;

use Centrex\Payroll\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryPayment extends Model
{
    use AddTablePrefix;

    #[\Override]
    protected function getTableSuffix(): string
    {
        return 'salary_payments';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('payroll.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'payroll_entry_id', 'employee_id', 'amount', 'method', 'paid_at', 'reference', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_at' => 'date',
    ];

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
