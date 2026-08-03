<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Support;

use Centrex\Payroll\Enums\RepaymentMethod;
use Centrex\Payroll\Models\{EmployeeLoan, EmployeeLoanRepayment, PayrollEntry, SalaryPayment};

/**
 * Posts an approved PayrollEntry as a single journal entry in laravel-accounting, mirroring
 * ErpIntegration's soft-coupling pattern: payroll works fully standalone unless both
 * accounting_sync.enabled is true AND the accounting package is actually installed.
 *
 * Every PayrollAccount used on the entry must have an accounting_account_id and a
 * component_type ('earning' debits the mapped account, 'deduction' credits it) — if any
 * of them aren't mapped, posting throws rather than silently posting a partial (and
 * therefore misleading) entry. The net (earnings − deductions) is credited to the
 * salaries-payable control account, so a fully-mapped entry always balances by construction.
 *
 * sbu_code (business unit / company tag, same convention as laravel-accounting's own
 * documents) is derived from the entry's employees, not chosen by the caller: a PayrollEntry
 * is expected to represent one company's payroll run. If its employees carry more than one
 * distinct sbu_code, posting throws rather than silently commingling two companies' salary
 * expense into one journal entry — split the run into one PayrollEntry per company instead.
 */
class AccountingSync
{
    public function enabled(): bool
    {
        return (bool) config('payroll.accounting_sync.enabled', false)
            && class_exists(\Centrex\Accounting\Accounting::class)
            && class_exists(\Centrex\Accounting\Models\Account::class);
    }

    public function postPayrollEntry(PayrollEntry $entry): ?int
    {
        if (!$this->enabled() || $entry->journal_entry_id) {
            return null;
        }

        $allLines = $entry->lines()->with(['payrollAccount', 'employee'])->get();
        $sbuCode = $this->resolveSbuCode($entry, $allLines);
        $lines = $allLines->groupBy('payroll_account_id');

        $journalLines = [];
        $unmapped = [];
        $totalEarnings = 0.0;
        $totalDeductions = 0.0;

        foreach ($lines as $accountLines) {
            $payrollAccount = $accountLines->first()?->payrollAccount;

            if (!$payrollAccount || !$payrollAccount->accounting_account_id) {
                $unmapped[] = $payrollAccount?->code ?? 'unknown';

                continue;
            }

            $sum = round((float) $accountLines->sum('amount'), 2);

            if ($payrollAccount->component_type === 'deduction') {
                $journalLines[] = [
                    'account_id'  => $payrollAccount->accounting_account_id,
                    'type'        => 'credit',
                    'amount'      => $sum,
                    'description' => $payrollAccount->name,
                ];
                $totalDeductions += $sum;
            } else {
                $journalLines[] = [
                    'account_id'  => $payrollAccount->accounting_account_id,
                    'type'        => 'debit',
                    'amount'      => $sum,
                    'description' => $payrollAccount->name,
                ];
                $totalEarnings += $sum;
            }
        }

        // All-or-nothing: posting only the mapped subset would misstate the entry's real
        // earnings/deductions, so every payroll account it uses must have a GL account first.
        if ($unmapped !== []) {
            throw new \RuntimeException(
                'Could not post payroll entry ' . $entry->entry_number . ' to accounting: '
                . 'payroll account(s) ' . implode(', ', array_unique($unmapped)) . ' have no GL account mapped. '
                . 'Set accounting_account_id on each one first.',
            );
        }

        $netPayable = round($totalEarnings - $totalDeductions, 2);

        if ($netPayable > 0) {
            $payableCode = (string) config('payroll.accounts.salaries_payable');
            $payableAccount = \Centrex\Accounting\Models\Account::where('code', $payableCode)
                ->where('is_active', true)
                ->first();

            if (!$payableAccount) {
                throw new \RuntimeException("Payroll salaries-payable account (code {$payableCode}) not found or inactive.");
            }

            $journalLines[] = [
                'account_id'  => $payableAccount->id,
                'type'        => 'credit',
                'amount'      => $netPayable,
                'description' => 'Net salaries payable — ' . $entry->entry_number,
            ];
        }

        $accounting = app(\Centrex\Accounting\Accounting::class);

        $journalEntry = $accounting->createJournalEntry([
            'date'          => $entry->date,
            'reference'     => $entry->entry_number,
            'type'          => 'general',
            'description'   => $entry->description ?: ('Payroll — ' . $entry->entry_number),
            'currency'      => $entry->currency,
            'exchange_rate' => $entry->exchange_rate,
            'sbu_code'      => $sbuCode,
            'lines'         => $journalLines,
        ]);

        $journalEntry->post();

        $entry->update(['journal_entry_id' => $journalEntry->id]);

        return (int) $journalEntry->id;
    }

    /**
     * Posts a salary disbursement as its own balanced journal entry, debiting the
     * salaries-payable control account (the same one credited on approval — see
     * postPayrollEntry above) and crediting the cash/bank account the money actually left
     * from. One journal entry per SalaryPayment, since payments are often partial.
     *
     * $accountCode lets the caller pick the disbursement account (e.g. a specific bank
     * account vs cash) the same way laravel-accounting's recordInvoicePayment/
     * recordBillPayment do; it defaults to config('payroll.accounts.default_cash').
     */
    public function postSalaryPayment(SalaryPayment $payment, ?string $accountCode = null): ?int
    {
        if (!$this->enabled() || $payment->journal_entry_id) {
            return null;
        }

        $payment->loadMissing(['payrollEntry', 'employee']);
        $entry = $payment->payrollEntry;

        $payableCode = (string) config('payroll.accounts.salaries_payable');
        $payableAccount = \Centrex\Accounting\Models\Account::where('code', $payableCode)
            ->where('is_active', true)
            ->first();

        if (!$payableAccount) {
            throw new \RuntimeException("Payroll salaries-payable account (code {$payableCode}) not found or inactive.");
        }

        $cashCode = $accountCode ?: (string) config('payroll.accounts.default_cash', '1000');
        $cashAccount = \Centrex\Accounting\Models\Account::where('code', $cashCode)
            ->where('is_active', true)
            ->first();

        if (!$cashAccount) {
            throw new \RuntimeException("Payroll disbursement account (code {$cashCode}) not found or inactive.");
        }

        $amount = round((float) $payment->amount, 2);

        $accounting = app(\Centrex\Accounting\Accounting::class);

        $journalEntry = $accounting->createJournalEntry([
            'date'        => $payment->paid_at,
            'reference'   => $entry->entry_number . '-PMT-' . $payment->id,
            'type'        => 'general',
            'description' => 'Salary payment — ' . $entry->entry_number
                . ' (' . ($payment->employee?->name ?? ('employee #' . $payment->employee_id)) . ')',
            'currency'      => $entry->currency,
            'exchange_rate' => $entry->exchange_rate,
            'sbu_code'      => $payment->employee?->sbu_code,
            'lines'         => [
                ['account_id' => $payableAccount->id, 'type' => 'debit', 'amount' => $amount, 'description' => 'Salaries payable settled'],
                ['account_id' => $cashAccount->id, 'type' => 'credit', 'amount' => $amount, 'description' => 'Salary disbursed — ' . ($payment->method ?? 'bank_transfer')],
            ],
        ]);

        $journalEntry->post();

        $payment->update(['journal_entry_id' => $journalEntry->id]);

        return (int) $journalEntry->id;
    }

    /**
     * Posts a loan/advance disbursement: debits the employee-loan-receivable control account
     * and credits the cash/bank account the money actually left from. Called on approval
     * (Payroll::approveLoan() disburses the full loan amount in one go — there's no partial
     * disbursement, unlike salary payments).
     *
     * $cashAccountCode mirrors postSalaryPayment()'s $accountCode parameter; defaults to
     * config('payroll.accounts.default_cash').
     */
    public function postLoanDisbursement(EmployeeLoan $loan, ?string $cashAccountCode = null): ?int
    {
        if (!$this->enabled() || $loan->journal_entry_id) {
            return null;
        }

        $loan->loadMissing('employee');

        $receivableAccount = $this->requireAccount(
            (string) config('payroll.accounts.employee_loan_receivable', '1450'),
            'employee-loan-receivable',
        );
        $cashAccount = $this->requireAccount(
            $cashAccountCode ?: (string) config('payroll.accounts.default_cash', '1000'),
            'disbursement',
        );

        $amount = round((float) $loan->disbursed_amount, 2);

        $accounting = app(\Centrex\Accounting\Accounting::class);

        $journalEntry = $accounting->createJournalEntry([
            'date'        => $loan->approved_at?->toDateString() ?? $loan->issue_date,
            'reference'   => $loan->loan_number,
            'type'        => 'general',
            'description' => 'Loan disbursed — ' . $loan->loan_number
                . ' (' . ($loan->employee?->name ?? ('employee #' . $loan->employee_id)) . ')',
            'currency'      => $loan->currency,
            'exchange_rate' => 1.0,
            'sbu_code'      => $loan->employee?->sbu_code,
            'lines'         => [
                ['account_id' => $receivableAccount->id, 'type' => 'debit', 'amount' => $amount, 'description' => 'Employee loan/advance disbursed'],
                ['account_id' => $cashAccount->id, 'type' => 'credit', 'amount' => $amount, 'description' => 'Loan disbursed — ' . $loan->repayment_method->value],
            ],
        ]);

        $journalEntry->post();

        $loan->update(['journal_entry_id' => $journalEntry->id]);

        return (int) $journalEntry->id;
    }

    /**
     * Posts a loan/advance repayment: credits the employee-loan-receivable control account.
     * The debit side depends on how the repayment actually happened — a salary_deduction
     * repayment never moves cash (it was withheld from the payslip), so it debits
     * salaries-payable instead of cash/bank, reducing what's owed to the employee.
     *
     * $accountCode (cash/bank_transfer repayments only) mirrors postSalaryPayment()'s
     * $accountCode parameter; defaults to config('payroll.accounts.default_cash').
     */
    public function postLoanRepayment(EmployeeLoanRepayment $repayment, ?string $accountCode = null): ?int
    {
        if (!$this->enabled() || $repayment->journal_entry_id) {
            return null;
        }

        $repayment->loadMissing('loan.employee');
        $loan = $repayment->loan;

        $receivableAccount = $this->requireAccount(
            (string) config('payroll.accounts.employee_loan_receivable', '1450'),
            'employee-loan-receivable',
        );

        $debitAccount = $repayment->method === RepaymentMethod::SalaryDeduction
            ? $this->requireAccount((string) config('payroll.accounts.salaries_payable'), 'salaries-payable')
            : $this->requireAccount($accountCode ?: (string) config('payroll.accounts.default_cash', '1000'), 'repayment');

        $amount = round((float) $repayment->amount, 2);

        $accounting = app(\Centrex\Accounting\Accounting::class);

        $journalEntry = $accounting->createJournalEntry([
            'date'        => $repayment->repaid_at,
            'reference'   => $loan->loan_number . '-RPY-' . $repayment->id,
            'type'        => 'general',
            'description' => 'Loan repayment — ' . $loan->loan_number
                . ' (' . ($loan->employee?->name ?? ('employee #' . $loan->employee_id)) . ') via ' . $repayment->method->label(),
            'currency'      => $loan->currency,
            'exchange_rate' => 1.0,
            'sbu_code'      => $loan->employee?->sbu_code,
            'lines'         => [
                ['account_id' => $debitAccount->id, 'type' => 'debit', 'amount' => $amount, 'description' => 'Loan repayment'],
                ['account_id' => $receivableAccount->id, 'type' => 'credit', 'amount' => $amount, 'description' => 'Employee loan/advance repaid'],
            ],
        ]);

        $journalEntry->post();

        $repayment->update(['journal_entry_id' => $journalEntry->id]);

        return (int) $journalEntry->id;
    }

    private function requireAccount(string $code, string $label): \Centrex\Accounting\Models\Account
    {
        $account = \Centrex\Accounting\Models\Account::where('code', $code)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            throw new \RuntimeException("Payroll {$label} account (code {$code}) not found or inactive.");
        }

        return $account;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, \Centrex\Payroll\Models\PayrollEntryLine>  $lines
     */
    private function resolveSbuCode(PayrollEntry $entry, \Illuminate\Database\Eloquent\Collection $lines): ?string
    {
        $codes = $lines
            ->map(fn ($line) => $line->employee?->sbu_code)
            ->filter(fn (?string $code): bool => $code !== null && $code !== '')
            ->unique();

        if ($codes->count() > 1) {
            throw new \RuntimeException(
                'Could not post payroll entry ' . $entry->entry_number . ' to accounting: '
                . 'its employees span multiple companies (' . $codes->implode(', ') . '). '
                . 'Split this run into one payroll entry per company (sbu_code) instead.',
            );
        }

        return $codes->first();
    }
}
