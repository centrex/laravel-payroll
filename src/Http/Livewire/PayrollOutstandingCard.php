<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire;

use Centrex\Payroll\Models\{PayrollEntry, SalaryPayment};
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\{Blade, DB};
use Livewire\Component;

/**
 * Split out of PayrollDashboard — salaryOutstanding() is a 3-table join aggregate plus a
 * second sum() query; moderate cost, but cached here so repeat dashboard views don't re-pay
 * it every time.
 */
class PayrollOutstandingCard extends Component
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

    /** Net (earnings − deductions) across every approved entry, minus everything paid so far. */
    public function salaryOutstanding(): float
    {
        return $this->rememberCache(
            $this->cacheKey('payroll', 'outstanding-card'),
            function (): float {
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
            },
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading" class="rounded-2xl border border-base-300 bg-base-100 p-4 shadow-theme-xs animate-pulse space-y-2">
                <div class="h-3 w-24 rounded bg-base-300"></div>
                <div class="h-6 w-28 rounded bg-base-300"></div>
                <div class="h-2 w-20 rounded bg-base-300"></div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('payroll::livewire.payroll-outstanding-card', [
            'salaryOutstanding' => $this->salaryOutstanding(),
        ]);
    }
}
