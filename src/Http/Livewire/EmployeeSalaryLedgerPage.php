<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire;

use Centrex\Payroll\Facades\Payroll;
use Centrex\Payroll\Models\{Employee, PayrollEntry};
use Centrex\Payroll\Support\AccountingSync;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class EmployeeSalaryLedgerPage extends Component
{
    public ?int $employeeId = null;

    // Record payment modal
    public bool $showPayModal = false;

    public ?int $payEntryId = null;

    public string $payEntryNumber = '';

    public float $payOutstanding = 0;

    public float $payAmount = 0;

    public string $payMethod = 'bank_transfer';

    public string $payDate = '';

    public string $payReference = '';

    public string $payNotes = '';

    protected array $queryString = ['employeeId'];

    public function mount(): void
    {
        $this->payDate = now()->format('Y-m-d');
    }

    public function openPay(int $entryId): void
    {
        $entry = PayrollEntry::findOrFail($entryId);
        $outstanding = Payroll::netPayableForEmployee($entry, $this->employeeId)
            - Payroll::totalPaidForEmployee($entry, $this->employeeId);

        $this->payEntryId = $entryId;
        $this->payEntryNumber = $entry->entry_number;
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

        $entry = PayrollEntry::findOrFail($this->payEntryId);

        try {
            DB::transaction(function () use ($entry): void {
                $payment = Payroll::recordSalaryPayment($entry, (int) $this->employeeId, [
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
        $employees = Employee::query()->where('is_active', true)->orderBy('name')->get();

        $ledger = $this->employeeId
            ? Payroll::getEmployeeSalaryLedger($this->employeeId)
            : null;

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('payroll::livewire.employee-salary-ledger', [
            'employees' => $employees,
            'ledger'    => $ledger,
        ])->layout($layout, ['title' => __('Employee Salary Ledger')]);
    }
}
