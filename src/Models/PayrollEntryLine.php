<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Models;

use Centrex\Payroll\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntryLine extends Model
{
    use AddTablePrefix;

    #[\Override]
    protected function getTableSuffix(): string
    {
        return 'payroll_entry_lines';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('payroll.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'payroll_entry_id', 'employee_id', 'payroll_account_id', 'amount', 'description', 'reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollAccount(): BelongsTo
    {
        return $this->belongsTo(PayrollAccount::class);
    }
}
