<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire;

use Centrex\Payroll\Enums\LoanStatus;
use Centrex\Payroll\Models\{Employee, EmployeeLoan, PayrollEntry, SalaryPayment};
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PayrollDashboard extends Component
{
    // ── Headline stats ───────────────────────────────────────────────────────

    private function headlineStats(): array
    {
        $employees = Employee::query();

        return [
            'total_employees'  => $employees->count(),
            'active_employees' => (clone $employees)->where('is_active', true)->count(),
            'runs_this_month'  => PayrollEntry::whereYear('date', now()->year)->whereMonth('date', now()->month)->count(),
            'pending_approval' => PayrollEntry::where('status', 'draft')->count(),
        ];
    }

    private function loanStats(): array
    {
        return [
            'active_count'        => EmployeeLoan::where('status', LoanStatus::Active->value)->count(),
            'outstanding_balance' => (float) EmployeeLoan::where('status', LoanStatus::Active->value)->sum('outstanding_balance'),
        ];
    }

    public function render(): View
    {
        // salaryOutstanding() and the three charts (payrollCostTrend/earningsVsDeductions/
        // loanStatusDistribution) moved to their own lazy-loaded components
        // (PayrollOutstandingCard, PayrollChartsCard) — see dashboard.blade.php.
        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('payroll::livewire.dashboard', [
            'headline'       => $this->headlineStats(),
            'loanStats'      => $this->loanStats(),
            'recentEntries'  => PayrollEntry::latest('date')->limit(8)->get(),
            'recentPayments' => SalaryPayment::with(['employee', 'payrollEntry'])->latest('paid_at')->limit(8)->get(),
        ])->layout($layout, ['title' => __('Payroll Dashboard')]);
    }
}
