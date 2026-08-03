<?php

declare(strict_types = 1);

use Centrex\Payroll\Models\{Employee, EmployeeLoan, EmployeeLoanRepayment};
use Centrex\Payroll\Support\AccountingSync;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'testing']);
});

// laravel-accounting isn't installed in this package's own test environment (it's a soft,
// optional dependency — see AccountingSync::enabled()), so postLoanDisbursement()/
// postLoanRepayment() must no-op safely here rather than trying to resolve accounting classes.
it('does not post or throw when accounting sync is disabled', function (): void {
    config()->set('payroll.accounting_sync.enabled', false);

    $employee = Employee::create([
        'code' => 'EMP-001', 'name' => 'Jane Doe', 'department' => 'Sales',
        'designation' => 'Sales Executive', 'employment_type' => 'full_time',
        'monthly_salary' => 50000, 'currency' => 'BDT', 'is_active' => true,
    ]);

    $loan = EmployeeLoan::create([
        'loan_number' => 'LOAN-TEST-00001', 'employee_id' => $employee->id,
        'type' => 'loan', 'status' => 'active', 'repayment_method' => 'cash',
        'amount' => 10000, 'disbursed_amount' => 10000, 'outstanding_balance' => 5000,
        'currency' => 'BDT', 'issue_date' => now()->toDateString(),
    ]);

    $repayment = EmployeeLoanRepayment::create([
        'employee_loan_id' => $loan->id, 'amount' => 5000,
        'method' => 'cash', 'repaid_at' => now()->toDateString(),
    ]);

    $sync = app(AccountingSync::class);

    expect($sync->postLoanDisbursement($loan))->toBeNull();
    expect($sync->postLoanRepayment($repayment))->toBeNull();
    expect($loan->fresh()->journal_entry_id)->toBeNull();
    expect($repayment->fresh()->journal_entry_id)->toBeNull();
});
