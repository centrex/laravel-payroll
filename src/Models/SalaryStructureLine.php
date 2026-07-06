<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Models;

use Centrex\Payroll\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructureLine extends Model
{
    use AddTablePrefix;

    #[\Override]
    protected function getTableSuffix(): string
    {
        return 'salary_structure_lines';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('payroll.drivers.database.connection') ?? config('database.default'));
    }

    protected $fillable = [
        'employee_id', 'payroll_account_id', 'calculation_type', 'amount', 'percentage', 'is_active',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'percentage' => 'decimal:4',
        'is_active'  => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollAccount(): BelongsTo
    {
        return $this->belongsTo(PayrollAccount::class);
    }
}
