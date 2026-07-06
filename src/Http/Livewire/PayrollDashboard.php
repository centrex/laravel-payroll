<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire;

use Centrex\Payroll\Enums\LoanStatus;
use Centrex\Payroll\Models\{Employee, EmployeeLoan, PayrollEntry, SalaryPayment};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class PayrollDashboard extends Component
{
    private function connection(): ?string
    {
        return config('payroll.drivers.database.connection') ?? config('database.default');
    }

    private function prefix(): string
    {
        return config('payroll.table_prefix', 'pay_');
    }

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

    /** Net (earnings − deductions) across every approved entry, minus everything paid so far. */
    private function salaryOutstanding(): float
    {
        $net = (float) DB::connection($this->connection())
            ->table($this->prefix() . 'payroll_entry_lines as l')
            ->join($this->prefix() . 'payroll_accounts as a', 'a.id', '=', 'l.payroll_account_id')
            ->join($this->prefix() . 'payroll_entries as e', 'e.id', '=', 'l.payroll_entry_id')
            ->where('e.status', 'approved')
            ->selectRaw("SUM(CASE WHEN a.component_type = 'deduction' THEN -l.amount ELSE l.amount END) as net")
            ->value('net') ?? 0;

        $paid = (float) SalaryPayment::query()
            ->whereIn('payroll_entry_id', PayrollEntry::where('status', 'approved')->pluck('id'))
            ->sum('amount');

        return round($net - $paid, 2);
    }

    private function loanStats(): array
    {
        return [
            'active_count'        => EmployeeLoan::where('status', LoanStatus::Active->value)->count(),
            'outstanding_balance' => (float) EmployeeLoan::where('status', LoanStatus::Active->value)->sum('outstanding_balance'),
        ];
    }

    // ── Charts ────────────────────────────────────────────────────────────────

    private function payrollCostTrend(): array
    {
        $months = 6;
        $from = now()->subMonths($months - 1)->startOfMonth();

        $rows = DB::connection($this->connection())
            ->table($this->prefix() . 'payroll_entry_lines as l')
            ->join($this->prefix() . 'payroll_accounts as a', 'a.id', '=', 'l.payroll_account_id')
            ->join($this->prefix() . 'payroll_entries as e', 'e.id', '=', 'l.payroll_entry_id')
            ->where('e.status', 'approved')
            ->where('e.date', '>=', $from->toDateString())
            ->selectRaw("DATE_FORMAT(e.date, '%Y-%m') as ym, SUM(CASE WHEN a.component_type = 'deduction' THEN -l.amount ELSE l.amount END) as net")
            ->groupBy('ym')
            ->pluck('net', 'ym');

        $categories = [];
        $data = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $categories[] = $month->format('M Y');
            $data[] = round((float) ($rows->get($month->format('Y-m')) ?? 0), 2);
        }

        return [
            'series'     => [['name' => 'Net Payroll Cost', 'data' => $data]],
            'categories' => $categories,
        ];
    }

    private function earningsVsDeductions(): array
    {
        $rows = DB::connection($this->connection())
            ->table($this->prefix() . 'payroll_entry_lines as l')
            ->join($this->prefix() . 'payroll_accounts as a', 'a.id', '=', 'l.payroll_account_id')
            ->join($this->prefix() . 'payroll_entries as e', 'e.id', '=', 'l.payroll_entry_id')
            ->where('e.status', 'approved')
            ->whereYear('e.date', now()->year)
            ->whereMonth('e.date', now()->month)
            ->selectRaw('a.component_type, SUM(l.amount) as total')
            ->groupBy('a.component_type')
            ->pluck('total', 'component_type');

        return [
            'series' => [
                round((float) ($rows->get('earning') ?? 0), 2),
                round((float) ($rows->get('deduction') ?? 0), 2),
            ],
            'categories' => ['Earnings', 'Deductions'],
        ];
    }

    private function loanStatusDistribution(): array
    {
        $counts = EmployeeLoan::selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $statuses = array_map(fn (LoanStatus $s): string => $s->value, LoanStatus::cases());

        return [
            'series'     => array_map(fn (string $s): int => (int) ($counts[$s] ?? 0), $statuses),
            'categories' => array_map(fn (LoanStatus $s): string => $s->label(), LoanStatus::cases()),
        ];
    }

    public function render(): View
    {
        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('payroll::livewire.dashboard', [
            'headline'          => $this->headlineStats(),
            'salaryOutstanding' => $this->salaryOutstanding(),
            'loanStats'         => $this->loanStats(),
            'costTrendChart'    => $this->payrollCostTrend(),
            'earningsChart'     => $this->earningsVsDeductions(),
            'loanChart'         => $this->loanStatusDistribution(),
            'recentEntries'     => PayrollEntry::latest('date')->limit(8)->get(),
            'recentPayments'    => SalaryPayment::with(['employee', 'payrollEntry'])->latest('paid_at')->limit(8)->get(),
        ])->layout($layout, ['title' => __('Payroll Dashboard')]);
    }
}
