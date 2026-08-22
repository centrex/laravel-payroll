<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire;

use Centrex\Payroll\Facades\Payroll;
use Centrex\Payroll\Jobs\{PostPayrollEntryToAccountingJob, PostSalaryPaymentToAccountingJob};
use Centrex\Payroll\Models\{Employee, PayrollEntry};
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PayrollEntryShowPage extends Component
{
    public int $payrollEntryId;

    // Record payment modal
    public bool $showPayModal = false;

    public ?int $payEmployeeId = null;

    public string $payEmployeeName = '';

    public float $payOutstanding = 0;

    public float $payAmount = 0;

    public string $payMethod = 'bank_transfer';

    public string $payDate = '';

    public string $payReference = '';

    public string $payNotes = '';

    public function mount(int $payrollEntryId): void
    {
        $this->payrollEntryId = $payrollEntryId;
        $this->payDate = now()->format('Y-m-d');
    }

    public function approve(): void
    {
        $entry = PayrollEntry::findOrFail($this->payrollEntryId);

        if ($entry->status !== 'draft') {
            $this->dispatch('notify', type: 'warning', message: 'Only draft payroll entries can be approved.');

            return;
        }

        $entry->update([
            'status'      => 'approved',
            'approved_at' => now(),
        ]);

        // Queued — see PostPayrollEntryToAccountingJob's docblock. A posting failure no
        // longer blocks approval; it's recorded onto the entry's accounting_sync_error
        // column instead.
        PostPayrollEntryToAccountingJob::dispatch($entry->id)->afterCommit();

        $this->dispatch('notify', type: 'success', message: "Payroll {$entry->entry_number} approved.");
    }

    public function retryAccountingSync(): void
    {
        $entry = PayrollEntry::findOrFail($this->payrollEntryId);
        $entry->forceFill(['accounting_sync_error' => null])->saveQuietly();

        PostPayrollEntryToAccountingJob::dispatch($entry->id)->afterCommit();

        $this->dispatch('notify', type: 'info', message: 'Retrying accounting sync…');
    }

    public function openPay(int $employeeId): void
    {
        $entry = PayrollEntry::findOrFail($this->payrollEntryId);
        $outstanding = Payroll::netPayableForEmployee($entry, $employeeId)
            - Payroll::totalPaidForEmployee($entry, $employeeId);

        $this->payEmployeeId = $employeeId;
        $this->payEmployeeName = (string) (Employee::find($employeeId)?->name ?? '');
        $this->payOutstanding = round($outstanding, 2);
        $this->payAmount = round($outstanding, 2);
        $this->payMethod = 'bank_transfer';
        $this->payDate = now()->format('Y-m-d');
        $this->payReference = '';
        $this->payNotes = '';
        $this->showPayModal = true;
    }

    public function recordPayment(): void
    {
        $this->validate([
            'payAmount'    => 'required|numeric|min:0.01',
            'payMethod'    => 'required|in:cash,bank_transfer,mobile_banking,cheque',
            'payDate'      => 'required|date',
            'payReference' => 'nullable|string|max:200',
            'payNotes'     => 'nullable|string|max:1000',
        ]);

        $entry = PayrollEntry::findOrFail($this->payrollEntryId);

        try {
            $payment = Payroll::recordSalaryPayment($entry, (int) $this->payEmployeeId, [
                'amount'    => $this->payAmount,
                'method'    => $this->payMethod,
                'paid_at'   => $this->payDate,
                'reference' => $this->payReference ?: null,
                'notes'     => $this->payNotes ?: null,
            ]);
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        // Queued — see PostPayrollEntryToAccountingJob's docblock (same pattern).
        PostSalaryPaymentToAccountingJob::dispatch($payment->id)->afterCommit();

        $this->dispatch('notify', type: 'success', message: 'Salary payment recorded.');
        $this->showPayModal = false;
    }

    public function render(): View
    {
        $entry = PayrollEntry::query()
            ->with(['lines.employee', 'lines.payrollAccount', 'payments.employee'])
            ->findOrFail($this->payrollEntryId);

        $employeeRows = $entry->lines
            ->groupBy('employee_id')
            ->map(function ($lines) use ($entry): array {
                $employee = $lines->first()->employee;
                $earnings = (float) $lines
                    ->filter(fn ($line): bool => ($line->payrollAccount?->component_type ?? 'earning') !== 'deduction')
                    ->sum('amount');
                $deductions = (float) $lines
                    ->filter(fn ($line): bool => $line->payrollAccount?->component_type === 'deduction')
                    ->sum('amount');
                $netPayable = round($earnings - $deductions, 2);
                $paid = round(Payroll::totalPaidForEmployee($entry, (int) $employee->id), 2);

                return [
                    'employee'    => $employee,
                    'lines'       => $lines,
                    'earnings'    => $earnings,
                    'deductions'  => $deductions,
                    'net_payable' => $netPayable,
                    'paid'        => $paid,
                    'outstanding' => round($netPayable - $paid, 2),
                ];
            })
            ->values();

        $journalEntry = ($entry->journal_entry_id && class_exists(\Centrex\Accounting\Models\JournalEntry::class))
            ? \Centrex\Accounting\Models\JournalEntry::find($entry->journal_entry_id)
            : null;

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('payroll::livewire.payroll-entry-show', [
            'entry'        => $entry,
            'employeeRows' => $employeeRows,
            'journalEntry' => $journalEntry,
        ])->layout($layout, ['title' => 'Payroll ' . $entry->entry_number]);
    }
}
