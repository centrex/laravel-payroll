<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire;

use Centrex\Payroll\Facades\Payroll;
use Centrex\Payroll\Models\{Employee, PayrollEntry};
use Centrex\Payroll\Support\AccountingSync;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
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

        try {
            DB::transaction(function () use ($entry): void {
                $entry->update([
                    'status'      => 'approved',
                    'approved_at' => now(),
                ]);

                app(AccountingSync::class)->postPayrollEntry($entry);
            });
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('notify', type: 'success', message: "Payroll {$entry->entry_number} approved.");
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
            DB::transaction(function () use ($entry): void {
                $payment = Payroll::recordSalaryPayment($entry, (int) $this->payEmployeeId, [
                    'amount'    => $this->payAmount,
                    'method'    => $this->payMethod,
                    'paid_at'   => $this->payDate,
                    'reference' => $this->payReference ?: null,
                    'notes'     => $this->payNotes ?: null,
                ]);

                app(AccountingSync::class)->postSalaryPayment($payment);
            });

            $this->dispatch('notify', type: 'success', message: 'Salary payment recorded.');
            $this->showPayModal = false;
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
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
