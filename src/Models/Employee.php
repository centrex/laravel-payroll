<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Models;

use Centrex\Payroll\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    #[\Override]
    protected function getTableSuffix(): string
    {
        return 'employees';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('payroll.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'code', 'name', 'email', 'phone', 'address',
        'city', 'country', 'department', 'sbu_code', 'designation',
        'employment_type', 'joining_date', 'monthly_salary',
        'bank_account_name', 'bank_account_number',
        'emergency_contact_name', 'emergency_contact_phone',
        'tax_id', 'currency', 'credit_limit', 'payment_terms', 'is_active',
        'modelable_type', 'modelable_id',
        'user_id', 'commission_rate',
    ];

    protected $casts = [
        'joining_date'        => 'date',
        // Stored encrypted at rest (see 2026_08_11_000001_encrypt_sensitive_payroll_employee_columns) —
        // monthly_salary reads back as a numeric string, not a decimal-cast value, since it's no
        // longer queryable/sortable at the SQL level.
        'monthly_salary'      => 'encrypted',
        'bank_account_name'   => 'encrypted',
        'bank_account_number' => 'encrypted',
        'tax_id'              => 'encrypted',
        'credit_limit'        => 'decimal:2',
        'payment_terms'       => 'integer',
        'is_active'           => 'boolean',
        'commission_rate'     => 'decimal:4',
    ];

    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class);
    }

    public function activeLoans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class)->where('status', 'active');
    }

    public function getTotalOutstandingLoanAttribute(): float
    {
        return (float) $this->loans()->where('status', 'active')->sum('outstanding_balance');
    }
}
