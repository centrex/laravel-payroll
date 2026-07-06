<?php

declare(strict_types = 1);

namespace Centrex\Payroll;

use Centrex\Payroll\Enums\{LoanStatus, LoanType, RepaymentMethod};
use Centrex\Payroll\Exceptions\{InvalidLoanTransitionException, InvalidPayrollEntryStatusException, LoanRepaymentExceedsBalanceException, SalaryPaymentExceedsNetPayableException};
use Centrex\Payroll\Models\{Employee, EmployeeLoan, EmployeeLoanRepayment, PayrollEntry, SalaryPayment, SalaryStructureLine};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class Payroll
{
    /**
     * Issue a new loan or salary advance to an employee.
     *
     * @param  array{
     *     type: string,
     *     amount: float,
     *     repayment_method?: string,
     *     installments?: int,
     *     installment_amount?: float,
     *     issue_date: string,
     *     expected_completion_date?: string,
     *     currency?: string,
     *     notes?: string,
     * }  $data
     */
    public function issueLoan(int|Employee $employee, array $data): EmployeeLoan
    {
        $employee = $employee instanceof Employee ? $employee : Employee::findOrFail($employee);

        $loanType = LoanType::from($data['type']);
        $amount = (float) $data['amount'];

        return DB::transaction(function () use ($employee, $data, $loanType, $amount): EmployeeLoan {
            $installmentAmount = (float) ($data['installment_amount'] ?? 0);
            $installments = $data['installments'] ?? null;

            if ($installmentAmount <= 0 && $installments !== null && $installments > 0) {
                $installmentAmount = round($amount / $installments, 2);
            }

            return EmployeeLoan::create([
                'employee_id'              => $employee->id,
                'type'                     => $loanType->value,
                'status'                   => LoanStatus::Pending->value,
                'repayment_method'         => $data['repayment_method'] ?? RepaymentMethod::SalaryDeduction->value,
                'amount'                   => $amount,
                'disbursed_amount'         => 0,
                'outstanding_balance'      => 0,
                'installment_amount'       => $installmentAmount,
                'installments'             => $installments,
                'currency'                 => $data['currency'] ?? config('payroll.base_currency', 'BDT'),
                'issue_date'               => $data['issue_date'],
                'expected_completion_date' => $data['expected_completion_date'] ?? null,
                'notes'                    => $data['notes'] ?? null,
                'created_by'               => $data['created_by'] ?? null,
            ]);
        });
    }

    /**
     * Approve a pending loan and disburse it.
     */
    public function approveLoan(EmployeeLoan $loan, ?int $approvedBy = null): EmployeeLoan
    {
        if (!$loan->status->canTransitionTo(LoanStatus::Active)) {
            throw new InvalidLoanTransitionException(
                "Cannot approve loan [{$loan->loan_number}] with status [{$loan->status->label()}].",
            );
        }

        $loan->update([
            'status'              => LoanStatus::Active->value,
            'disbursed_amount'    => $loan->amount,
            'outstanding_balance' => $loan->amount,
            'approved_by'         => $approvedBy,
            'approved_at'         => now(),
        ]);

        return $loan->fresh();
    }

    /**
     * Record a repayment against an active loan.
     *
     * @param  array{
     *     amount: float,
     *     method?: string,
     *     repaid_at?: string,
     *     payroll_entry_id?: int,
     *     notes?: string,
     *     created_by?: int,
     * }  $data
     */
    public function recordRepayment(EmployeeLoan $loan, array $data): EmployeeLoanRepayment
    {
        if ($loan->status !== LoanStatus::Active) {
            throw new InvalidLoanTransitionException(
                "Cannot record repayment on loan [{$loan->loan_number}] with status [{$loan->status->label()}].",
            );
        }

        $amount = (float) $data['amount'];

        if ($amount > (float) $loan->outstanding_balance) {
            throw new LoanRepaymentExceedsBalanceException(
                "Repayment amount [{$amount}] exceeds outstanding balance [{$loan->outstanding_balance}].",
            );
        }

        return DB::transaction(function () use ($loan, $data, $amount): EmployeeLoanRepayment {
            $repayment = EmployeeLoanRepayment::create([
                'employee_loan_id' => $loan->id,
                'payroll_entry_id' => $data['payroll_entry_id'] ?? null,
                'amount'           => $amount,
                'method'           => $data['method'] ?? $loan->repayment_method->value,
                'repaid_at'        => $data['repaid_at'] ?? now()->toDateString(),
                'notes'            => $data['notes'] ?? null,
                'created_by'       => $data['created_by'] ?? null,
            ]);

            $newBalance = max(0.0, (float) $loan->outstanding_balance - $amount);

            $loan->update([
                'outstanding_balance' => $newBalance,
                'status'              => $newBalance <= 0.0 ? LoanStatus::Completed->value : $loan->status->value,
            ]);

            return $repayment;
        });
    }

    /**
     * Cancel a pending loan.
     */
    public function cancelLoan(EmployeeLoan $loan): EmployeeLoan
    {
        if (!$loan->status->canTransitionTo(LoanStatus::Cancelled)) {
            throw new InvalidLoanTransitionException(
                "Cannot cancel loan [{$loan->loan_number}] with status [{$loan->status->label()}].",
            );
        }

        $loan->update(['status' => LoanStatus::Cancelled->value]);

        return $loan->fresh();
    }

    /**
     * Get all active loans, optionally for a specific employee.
     *
     * @return Collection<int, EmployeeLoan>
     */
    public function getActiveLoans(?int $employeeId = null): Collection
    {
        return EmployeeLoan::query()
            ->with('employee')
            ->where('status', LoanStatus::Active->value)
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderBy('issue_date')
            ->get();
    }

    /**
     * Summary of all loans for a given employee.
     *
     * @return array{
     *     total_issued: float,
     *     total_disbursed: float,
     *     total_repaid: float,
     *     outstanding_balance: float,
     *     active_loans: int,
     *     pending_loans: int,
     *     completed_loans: int,
     * }
     */
    public function getLoanSummary(int|Employee $employee): array
    {
        $employee = $employee instanceof Employee ? $employee : Employee::findOrFail($employee);

        $loans = EmployeeLoan::query()
            ->where('employee_id', $employee->id)
            ->get();

        return [
            'total_issued'        => (float) $loans->sum('amount'),
            'total_disbursed'     => (float) $loans->sum('disbursed_amount'),
            'outstanding_balance' => (float) $loans->where('status', LoanStatus::Active->value)->sum('outstanding_balance'),
            'total_repaid'        => (float) EmployeeLoanRepayment::query()
                ->whereIn('employee_loan_id', $loans->pluck('id'))
                ->sum('amount'),
            'active_loans'    => $loans->where('status', LoanStatus::Active->value)->count(),
            'pending_loans'   => $loans->where('status', LoanStatus::Pending->value)->count(),
            'completed_loans' => $loans->where('status', LoanStatus::Completed->value)->count(),
        ];
    }

    /**
     * Net amount payable to an employee for one payroll entry: sum of 'earning' lines
     * minus sum of 'deduction' lines (by each line's PayrollAccount component_type).
     */
    public function netPayableForEmployee(PayrollEntry $entry, int $employeeId): float
    {
        $lines = $entry->lines()
            ->with('payrollAccount')
            ->where('employee_id', $employeeId)
            ->get();

        $earnings = (float) $lines
            ->filter(fn ($line): bool => ($line->payrollAccount?->component_type ?? 'earning') !== 'deduction')
            ->sum('amount');

        $deductions = (float) $lines
            ->filter(fn ($line): bool => $line->payrollAccount?->component_type === 'deduction')
            ->sum('amount');

        return round($earnings - $deductions, 2);
    }

    /**
     * Total already paid to an employee against one payroll entry.
     */
    public function totalPaidForEmployee(PayrollEntry $entry, int $employeeId): float
    {
        return (float) SalaryPayment::query()
            ->where('payroll_entry_id', $entry->id)
            ->where('employee_id', $employeeId)
            ->sum('amount');
    }

    /**
     * Record a salary disbursement to an employee against an approved payroll entry.
     *
     * @param  array{
     *     amount: float,
     *     method?: string,
     *     paid_at?: string,
     *     reference?: string,
     *     notes?: string,
     *     created_by?: int,
     * }  $data
     */
    public function recordSalaryPayment(PayrollEntry $entry, int $employeeId, array $data): SalaryPayment
    {
        if ($entry->status !== 'approved') {
            throw new InvalidPayrollEntryStatusException(
                "Cannot record a salary payment against payroll entry [{$entry->entry_number}] with status [{$entry->status}]. Only approved entries can be paid.",
            );
        }

        $amount = (float) $data['amount'];
        $netPayable = $this->netPayableForEmployee($entry, $employeeId);
        $alreadyPaid = $this->totalPaidForEmployee($entry, $employeeId);
        $outstanding = round($netPayable - $alreadyPaid, 2);

        if ($amount > $outstanding) {
            throw new SalaryPaymentExceedsNetPayableException(
                "Payment amount [{$amount}] exceeds the outstanding net payable [{$outstanding}] for this employee on entry [{$entry->entry_number}].",
            );
        }

        return SalaryPayment::create([
            'payroll_entry_id' => $entry->id,
            'employee_id'      => $employeeId,
            'amount'           => $amount,
            'method'           => $data['method'] ?? 'bank_transfer',
            'paid_at'          => $data['paid_at'] ?? now()->toDateString(),
            'reference'        => $data['reference'] ?? null,
            'notes'            => $data['notes'] ?? null,
            'created_by'       => $data['created_by'] ?? null,
        ]);
    }

    /**
     * Per-entry salary ledger for one employee: gross earnings, deductions, net payable,
     * paid so far, and outstanding — across every approved payroll entry they appear on.
     *
     * @return array{
     *     entries: array<int, array{id: int, entry_number: string, date: string, earnings: float, deductions: float, net_payable: float, paid: float, outstanding: float}>,
     *     total_net_payable: float,
     *     total_paid: float,
     *     total_outstanding: float,
     * }
     */
    public function getEmployeeSalaryLedger(int|Employee $employee): array
    {
        $employee = $employee instanceof Employee ? $employee : Employee::findOrFail($employee);

        $entries = PayrollEntry::query()
            ->whereHas('lines', fn ($q) => $q->where('employee_id', $employee->id))
            ->where('status', 'approved')
            ->with(['lines' => fn ($q) => $q->where('employee_id', $employee->id)->with('payrollAccount')])
            ->orderBy('date')
            ->get();

        $rows = [];
        $totalNetPayable = 0.0;
        $totalPaid = 0.0;

        foreach ($entries as $entry) {
            $earnings = (float) $entry->lines
                ->filter(fn ($line): bool => ($line->payrollAccount?->component_type ?? 'earning') !== 'deduction')
                ->sum('amount');
            $deductions = (float) $entry->lines
                ->filter(fn ($line): bool => $line->payrollAccount?->component_type === 'deduction')
                ->sum('amount');
            $netPayable = round($earnings - $deductions, 2);
            $paid = $this->totalPaidForEmployee($entry, $employee->id);
            $outstanding = round($netPayable - $paid, 2);

            $rows[] = [
                'id'           => $entry->id,
                'entry_number' => $entry->entry_number,
                'date'         => $entry->date->toDateString(),
                'earnings'     => $earnings,
                'deductions'   => $deductions,
                'net_payable'  => $netPayable,
                'paid'         => $paid,
                'outstanding'  => $outstanding,
            ];

            $totalNetPayable += $netPayable;
            $totalPaid += $paid;
        }

        return [
            'entries'           => $rows,
            'total_net_payable' => round($totalNetPayable, 2),
            'total_paid'        => round($totalPaid, 2),
            'total_outstanding' => round($totalNetPayable - $totalPaid, 2),
        ];
    }

    /**
     * Quick total: how much salary is still outstanding (unpaid) for an employee across
     * every approved payroll entry.
     */
    public function getEmployeeSalaryOutstanding(int|Employee $employee): float
    {
        return $this->getEmployeeSalaryLedger($employee)['total_outstanding'];
    }

    // -------------------------------------------------------------------------
    // Salary Structure (per-employee recurring template)
    // -------------------------------------------------------------------------

    /**
     * Active salary structure lines for an employee, with each line's Payroll Account loaded.
     *
     * @return Collection<int, SalaryStructureLine>
     */
    public function getSalaryStructure(int|Employee $employee): Collection
    {
        $employee = $employee instanceof Employee ? $employee : Employee::findOrFail($employee);

        return SalaryStructureLine::query()
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->with('payrollAccount')
            ->get();
    }

    /**
     * Create or update one salary structure line for an employee.
     *
     * @param  array{calculation_type?: string, amount?: float, percentage?: float, is_active?: bool}  $data
     */
    public function setSalaryStructureLine(int|Employee $employee, int $payrollAccountId, array $data): SalaryStructureLine
    {
        $employee = $employee instanceof Employee ? $employee : Employee::findOrFail($employee);

        return SalaryStructureLine::updateOrCreate(
            ['employee_id' => $employee->id, 'payroll_account_id' => $payrollAccountId],
            [
                'calculation_type' => $data['calculation_type'] ?? 'fixed',
                'amount'           => $data['amount'] ?? 0,
                'percentage'       => $data['percentage'] ?? null,
                'is_active'        => $data['is_active'] ?? true,
            ],
        );
    }

    public function removeSalaryStructureLine(int|Employee $employee, int $payrollAccountId): void
    {
        $employee = $employee instanceof Employee ? $employee : Employee::findOrFail($employee);

        SalaryStructureLine::where('employee_id', $employee->id)
            ->where('payroll_account_id', $payrollAccountId)
            ->delete();
    }

    /**
     * Resolve an employee's salary structure into concrete amounts, ready to use as payroll
     * entry lines. 'percentage_of_basic' lines are computed against whichever line's Payroll
     * Account matches config('payroll.salary_structure.basic_account_code').
     *
     * @return array<int, array{payroll_account_id: int, amount: float}>
     */
    public function generatePayrollLinesFromStructure(int|Employee $employee): array
    {
        $lines = $this->getSalaryStructure($employee);
        $basicCode = (string) config('payroll.salary_structure.basic_account_code', 'BASIC');

        $basicLine = $lines->first(fn (SalaryStructureLine $line): bool => $line->payrollAccount?->code === $basicCode);
        $basicAmount = (float) ($basicLine->amount ?? 0);

        return $lines->map(function (SalaryStructureLine $line) use ($basicAmount): array {
            $amount = $line->calculation_type === 'percentage_of_basic'
                ? round($basicAmount * (float) $line->percentage / 100, 2)
                : (float) $line->amount;

            return [
                'payroll_account_id' => $line->payroll_account_id,
                'amount'             => $amount,
            ];
        })->values()->all();
    }

    // -------------------------------------------------------------------------
    // Sales Commission
    // -------------------------------------------------------------------------

    /**
     * Commission earned by an employee over a period: their commission_rate % of the
     * subtotal (net of tax/shipping) of every non-cancelled, non-draft sale order where
     * they're the credited sales executive (Centrex\Inventory\Models\SaleOrder::sales_executive_id,
     * matched via Employee::user_id). Returns 0 if the employee has no user_id or commission
     * rate set, or if laravel-inventory isn't installed — commission is entirely optional.
     */
    public function calculateSalesCommission(int|Employee $employee, string $from, string $to): float
    {
        $employee = $employee instanceof Employee ? $employee : Employee::findOrFail($employee);

        if (!$employee->user_id || !$employee->commission_rate || !class_exists(\Centrex\Inventory\Models\SaleOrder::class)) {
            return 0.0;
        }

        $totalSales = (float) \Centrex\Inventory\Models\SaleOrder::query()
            ->where('sales_executive_id', $employee->user_id)
            ->where('document_type', 'order')
            ->whereNotIn('status', ['draft', 'cancelled', 'returned'])
            ->whereBetween('ordered_at', [$from, $to])
            ->sum('subtotal_amount');

        return round($totalSales * (float) $employee->commission_rate / 100, 2);
    }
}
