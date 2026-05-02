<?php

declare(strict_types = 1);

namespace Centrex\Payroll;

use Centrex\Payroll\Enums\{LoanStatus, LoanType, RepaymentMethod};
use Centrex\Payroll\Exceptions\{InvalidLoanTransitionException, LoanRepaymentExceedsBalanceException};
use Centrex\Payroll\Models\{Employee, EmployeeLoan, EmployeeLoanRepayment};
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
}
