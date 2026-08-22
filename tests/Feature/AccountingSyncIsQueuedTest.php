<?php

declare(strict_types = 1);

use Centrex\Payroll\Http\Livewire\{EmployeeLoansPage, EmployeeSalaryLedgerPage, PayrollEntriesPage};
use Centrex\Payroll\Jobs\{PostLoanDisbursementToAccountingJob, PostLoanRepaymentToAccountingJob, PostPayrollEntryToAccountingJob, PostSalaryPaymentToAccountingJob};
use Centrex\Payroll\Models\{Employee, EmployeeLoan, PayrollAccount, PayrollEntry, PayrollEntryLine};
use Illuminate\Support\Facades\{Artisan, Queue};

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'testing']);
});

/**
 * Regression coverage for moving AccountingSync::post*() off the request thread (see the
 * Jobs' own docblocks) — approving a payroll entry/loan or recording a payment/repayment used
 * to call AccountingSync inline inside the same DB::transaction() as the core write, so an
 * unmapped GL account rolled back the whole action. These tests assert the queued dispatch
 * itself happens; without them a regression back to the inline call would still pass every
 * other test in this suite (none of them exercise this path), since this package's own test
 * environment doesn't have laravel-accounting installed to exercise a real posting failure.
 */
function makeEmployee(): Employee
{
    return Employee::create([
        'code'           => 'EMP-Q-1', 'name' => 'Queue Test Employee', 'department' => 'Sales',
        'designation'    => 'Sales Executive', 'employment_type' => 'full_time',
        'monthly_salary' => 50000, 'currency' => 'BDT', 'is_active' => true,
    ]);
}

function makePayrollAccount(): PayrollAccount
{
    return PayrollAccount::create([
        'code'     => 'BASIC-Q', 'name' => 'Basic Salary', 'component_type' => 'earning',
        'currency' => 'BDT', 'is_active' => true,
    ]);
}

it('dispatches the accounting post job when a payroll entry is approved', function (): void {
    Queue::fake();

    $employee = makeEmployee();
    $entry = PayrollEntry::create([
        'date'          => now()->toDateString(), 'type' => 'salary', 'currency' => 'BDT',
        'exchange_rate' => 1, 'status' => 'draft', 'description' => 'Queue test run',
    ]);
    PayrollEntryLine::create(['payroll_entry_id' => $entry->id, 'employee_id' => $employee->id, 'payroll_account_id' => makePayrollAccount()->id, 'amount' => 1000]);

    $page = new PayrollEntriesPage;
    $page->approve($entry->id);

    Queue::assertPushed(PostPayrollEntryToAccountingJob::class, fn ($job): bool => $job->payrollEntryId === $entry->id);
    expect($entry->fresh()->status)->toBe('approved');
});

it('dispatches the accounting post job when a loan is approved and when it is repaid', function (): void {
    Queue::fake();

    $employee = makeEmployee();
    $loan = EmployeeLoan::create([
        'loan_number' => 'LOAN-Q-00001', 'employee_id' => $employee->id,
        'type'        => 'loan', 'status' => 'pending', 'repayment_method' => 'cash',
        'amount'      => 10000, 'disbursed_amount' => 0, 'outstanding_balance' => 10000,
        'currency'    => 'BDT', 'issue_date' => now()->toDateString(),
    ]);

    $page = new EmployeeLoansPage;
    $page->approve($loan->id);

    Queue::assertPushed(PostLoanDisbursementToAccountingJob::class, fn ($job): bool => $job->employeeLoanId === $loan->id);
    expect($loan->fresh()->status->value)->toBe('active');

    $page->repayLoanId = $loan->id;
    $page->repayAmount = 4000;
    $page->repayMethod = 'cash';
    $page->repayDate = now()->format('Y-m-d');
    $page->repayNotes = '';
    $page->recordRepayment();

    Queue::assertPushed(PostLoanRepaymentToAccountingJob::class);
});

it('dispatches the accounting post job when a salary payment is recorded', function (): void {
    Queue::fake();

    $employee = makeEmployee();
    $entry = PayrollEntry::create([
        'date'          => now()->toDateString(), 'type' => 'salary', 'currency' => 'BDT',
        'exchange_rate' => 1, 'status' => 'approved', 'approved_at' => now(), 'description' => 'Queue test run 2',
    ]);
    PayrollEntryLine::create(['payroll_entry_id' => $entry->id, 'employee_id' => $employee->id, 'payroll_account_id' => makePayrollAccount()->id, 'amount' => 5000]);

    $page = new EmployeeSalaryLedgerPage;
    $page->employeeId = $employee->id;
    $page->payEntryId = $entry->id;
    $page->payAmount = 2000;
    $page->payMethod = 'bank_transfer';
    $page->payDate = now()->format('Y-m-d');
    $page->payReference = '';
    $page->payNotes = '';
    $page->recordPayment();

    Queue::assertPushed(PostSalaryPaymentToAccountingJob::class);
});

it('records the failure onto the entry when the accounting post job ultimately fails, and clears it on retry', function (): void {
    $employee = makeEmployee();
    $entry = PayrollEntry::create([
        'date'          => now()->toDateString(), 'type' => 'salary', 'currency' => 'BDT',
        'exchange_rate' => 1, 'status' => 'approved', 'approved_at' => now(), 'description' => 'Failure visibility test',
    ]);
    PayrollEntryLine::create(['payroll_entry_id' => $entry->id, 'employee_id' => $employee->id, 'payroll_account_id' => makePayrollAccount()->id, 'amount' => 1000]);

    $job = new PostPayrollEntryToAccountingJob($entry->id);
    $job->failed(new RuntimeException('Account [1300] is not mapped.'));

    expect($entry->fresh()->accounting_sync_error)->toBe('Account [1300] is not mapped.');

    // retryAccountingSync() clears the error immediately (optimistic) and re-dispatches —
    // the UI's retry button relies on this to stop showing the stale error right away.
    Queue::fake();
    $page = new PayrollEntriesPage;
    $page->retryAccountingSync($entry->id);

    expect($entry->fresh()->accounting_sync_error)->toBeNull();
    Queue::assertPushed(PostPayrollEntryToAccountingJob::class, fn ($job): bool => $job->payrollEntryId === $entry->id);
});
