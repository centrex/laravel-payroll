<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire;

use Centrex\Payroll\Enums\LoanStatus;
use Centrex\Payroll\Models\EmployeeLoan;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\{Blade, DB};
use Livewire\Component;

/**
 * Payroll Cost Trend / Earnings vs Deductions / Loan Status charts, split out of
 * PayrollDashboard. payrollCostTrend()/earningsVsDeductions() are 3-table join groupBys —
 * bundled into one lazy component with the cheap loanStatusDistribution() rather than three
 * separate round-trips.
 */
class PayrollChartsCard extends Component
{
    use CachesData;

    public function mount(): void
    {
        $this->cacheTtl = 300;
    }

    private function connection(): ?string
    {
        return config('payroll.drivers.database.connection') ?? config('database.default');
    }

    private function prefix(): string
    {
        return config('payroll.table_prefix', 'pay_');
    }

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

    /** @return array{costTrendChart: array, earningsChart: array, loanChart: array} */
    public function charts(): array
    {
        return $this->rememberCache(
            $this->cacheKey('payroll', 'charts-card'),
            fn (): array => [
                'costTrendChart' => $this->payrollCostTrend(),
                'earningsChart'  => $this->earningsVsDeductions(),
                'loanChart'      => $this->loanStatusDistribution(),
            ],
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading charts" class="space-y-4 mb-6 animate-pulse">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="h-64 rounded-2xl border border-base-200 bg-base-100"></div>
                    <div class="h-64 rounded-2xl border border-base-200 bg-base-100"></div>
                </div>
                <div class="h-64 rounded-2xl border border-base-200 bg-base-100"></div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('payroll::livewire.payroll-charts-card', $this->charts());
    }
}
