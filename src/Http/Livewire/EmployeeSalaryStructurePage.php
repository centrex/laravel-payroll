<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire;

use Centrex\Payroll\Facades\Payroll;
use Centrex\Payroll\Models\{Employee, PayrollAccount};
use Illuminate\Contracts\View\View;
use Livewire\Component;

class EmployeeSalaryStructurePage extends Component
{
    public ?int $employeeId = null;

    // Add/edit line form
    public bool $showLineModal = false;

    public ?int $payrollAccountId = null;

    public string $calculationType = 'fixed';

    public float $amount = 0;

    public float $percentage = 0;

    public bool $isActive = true;

    protected array $queryString = ['employeeId'];

    public function openLine(?int $payrollAccountId = null): void
    {
        $this->payrollAccountId = $payrollAccountId;
        $this->calculationType = 'fixed';
        $this->amount = 0;
        $this->percentage = 0;
        $this->isActive = true;

        if ($payrollAccountId) {
            $existing = Payroll::getSalaryStructure($this->employeeId)
                ->firstWhere('payroll_account_id', $payrollAccountId);

            if ($existing) {
                $this->calculationType = $existing->calculation_type;
                $this->amount = (float) $existing->amount;
                $this->percentage = (float) $existing->percentage;
                $this->isActive = (bool) $existing->is_active;
            }
        }

        $this->showLineModal = true;
    }

    public function saveLine(): void
    {
        $this->validate([
            'payrollAccountId' => 'required|integer|min:1',
            'calculationType'  => 'required|in:fixed,percentage_of_basic',
            'amount'           => 'nullable|numeric|min:0',
            'percentage'       => 'nullable|numeric|min:0|max:100',
        ]);

        Payroll::setSalaryStructureLine($this->employeeId, $this->payrollAccountId, [
            'calculation_type' => $this->calculationType,
            'amount'           => $this->amount,
            'percentage'       => $this->percentage,
            'is_active'        => $this->isActive,
        ]);

        $this->dispatch('notify', type: 'success', message: 'Salary structure line saved.');
        $this->showLineModal = false;
    }

    public function removeLine(int $payrollAccountId): void
    {
        Payroll::removeSalaryStructureLine($this->employeeId, $payrollAccountId);
        $this->dispatch('notify', type: 'success', message: 'Salary structure line removed.');
    }

    public function render(): View
    {
        $employees = Employee::query()->where('is_active', true)->orderBy('name')->get();
        $payrollAccounts = PayrollAccount::query()->where('is_active', true)->orderBy('name')->get();

        $structure = $this->employeeId ? Payroll::getSalaryStructure($this->employeeId) : collect();
        $generated = $this->employeeId ? Payroll::generatePayrollLinesFromStructure($this->employeeId) : [];
        $totalAmount = collect($generated)->sum('amount');

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('payroll::livewire.employee-salary-structure', [
            'employees'       => $employees,
            'payrollAccounts' => $payrollAccounts,
            'structure'       => $structure,
            'generated'       => collect($generated)->keyBy('payroll_account_id'),
            'totalAmount'     => $totalAmount,
        ])->layout($layout, ['title' => __('Employee Salary Structure')]);
    }
}
