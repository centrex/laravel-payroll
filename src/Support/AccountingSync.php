<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Support;

use Centrex\Payroll\Models\PayrollEntry;

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
