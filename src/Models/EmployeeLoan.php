<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Models;

use Centrex\Payroll\Concerns\AddTablePrefix;
use Centrex\Payroll\Enums\{LoanStatus, LoanType, RepaymentMethod};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Facades\DB;

class EmployeeLoan extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'employee_loans';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('payroll.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'loan_number', 'employee_id', 'type', 'status', 'repayment_method',
        'amount', 'disbursed_amount', 'outstanding_balance',
        'installment_amount', 'installments', 'currency',
        'issue_date', 'expected_completion_date', 'notes',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'type'             => LoanType::class,
        'status'           => LoanStatus::class,
        'repayment_method' => RepaymentMethod::class,
        'amount'           => 'decimal:2',
        'disbursed_amount' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
        'installment_amount'  => 'decimal:2',
        'installments'     => 'integer',
        'issue_date'       => 'date',
        'expected_completion_date' => 'date',
        'approved_at'      => 'datetime',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $loan): void {
            if ($loan->loan_number) {
                return;
            }

            DB::connection($loan->getConnectionName())->transaction(function () use ($loan): void {
                $prefix = $loan->type === LoanType::Advance ? 'ADV' : 'LOAN';
                $date   = now()->format('Ymd');

                $last = self::query()
                    ->whereDate('created_at', now()->toDateString())
                    ->where('type', $loan->type->value)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                $sequence = 1;

                if ($last && preg_match('/(\d+)$/', $last->loan_number, $matches)) {
                    $sequence = ((int) $matches[1]) + 1;
                }

                $loan->loan_number = sprintf('%s-%s-%05d', $prefix, $date, $sequence);
            });
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(EmployeeLoanRepayment::class);
    }

    public function getTotalRepaidAttribute(): float
    {
        return (float) $this->repayments()->sum('amount');
    }

    public function isFullyRepaid(): bool
    {
        return (float) $this->outstanding_balance <= 0.0;
    }
}
