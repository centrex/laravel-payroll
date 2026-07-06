<div>
    <x-tallui-notification />

    <x-tallui-page-header
        title="Payroll Dashboard"
        subtitle="Payroll overview — {{ now()->format('F Y') }}"
        icon="o-banknotes"
    >
        <x-slot:breadcrumbs>
            <x-tallui-breadcrumb :links="[['label' => 'Payroll'], ['label' => 'Dashboard']]" />
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-tallui-button label="Payroll Entries" icon="o-document-text" :link="route('payroll.entries.index')" class="btn-primary btn-sm" />
            <x-tallui-button label="Salary Ledger" icon="o-table-cells" :link="route('payroll.salary-ledger.index')" class="btn-ghost btn-sm" />
            <x-tallui-button label="Loans" icon="o-credit-card" :link="route('payroll.loans.index')" class="btn-ghost btn-sm" />
        </x-slot:actions>
    </x-tallui-page-header>

    {{-- ── Headline KPI row ────────────────────────────────────────────────────── --}}
    <div class="stats shadow w-full mb-6">
        <x-tallui-stat
            title="Total Employees"
            :value="$headline['total_employees']"
            icon="o-users"
            icon-color="text-primary"
            :desc="$headline['active_employees'] . ' active'"
        />
        <x-tallui-stat
            title="Payroll Runs This Month"
            :value="$headline['runs_this_month']"
            icon="o-document-text"
            icon-color="text-info"
            desc="Entries created"
        />
        <x-tallui-stat
            title="Pending Approval"
            :value="$headline['pending_approval']"
            icon="o-clock"
            icon-color="text-warning"
            desc="Draft entries"
        />
        <x-tallui-stat
            title="Salary Outstanding"
            :value="number_format($salaryOutstanding, 2)"
            icon="o-exclamation-circle"
            :icon-color="$salaryOutstanding > 0 ? 'text-warning' : 'text-success'"
            desc="Net payable, unpaid"
        />
    </div>

    {{-- ── Loans KPI row ───────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <x-tallui-card title="Employee Loans" icon="o-credit-card" :shadow="true" padding="normal">
            <div class="flex items-center justify-between mt-2">
                <div class="text-center">
                    <div class="text-3xl font-bold text-info">{{ $loanStats['active_count'] }}</div>
                    <div class="text-xs text-base-content/50 mt-1">Active Loans</div>
                </div>
                <div class="divider divider-horizontal"></div>
                <div class="text-center">
                    <div class="text-3xl font-bold text-warning">{{ number_format($loanStats['outstanding_balance'], 2) }}</div>
                    <div class="text-xs text-base-content/50 mt-1">Outstanding Balance</div>
                </div>
            </div>
            <div class="mt-3">
                <x-tallui-button label="Manage Loans" icon="o-credit-card" :link="route('payroll.loans.index')" class="btn-outline btn-sm w-full" />
            </div>
        </x-tallui-card>

        <x-tallui-card title="Quick Actions" icon="o-bolt" :shadow="true" padding="normal" class="md:col-span-2">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mt-2">
                <x-tallui-button label="Payroll Entries" icon="o-document-text" :link="route('payroll.entries.index')" class="btn-outline btn-sm" />
                <x-tallui-button label="Salary Ledger" icon="o-table-cells" :link="route('payroll.salary-ledger.index')" class="btn-outline btn-sm" />
                <x-tallui-button label="Employee Loans" icon="o-credit-card" :link="route('payroll.loans.index')" class="btn-outline btn-sm" />
                <x-tallui-button label="Employees" icon="o-users" :link="route('payroll.entities.employees.index')" class="btn-outline btn-sm" />
                <x-tallui-button label="Payroll Accounts" icon="o-clipboard-document-list" :link="route('payroll.entities.payroll-accounts.index')" class="btn-outline btn-sm" />
            </div>
        </x-tallui-card>
    </div>

    {{-- ── Charts row ──────────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <x-tallui-card title="Net Payroll Cost — Last 6 Months" icon="o-chart-bar" :shadow="true">
            @if (array_sum($costTrendChart['series'][0]['data']) > 0)
                <livewire:tallui-area-chart
                    :series="$costTrendChart['series']"
                    :categories="$costTrendChart['categories']"
                    :height="220"
                />
            @else
                <x-tallui-empty-state
                    title="No payroll history"
                    description="Approved payroll entries will appear here once posted."
                    icon="o-chart-bar"
                    size="sm"
                />
            @endif
        </x-tallui-card>

        <x-tallui-card title="Earnings vs Deductions — {{ now()->format('F Y') }}" icon="o-chart-pie" :shadow="true">
            @if (array_sum($earningsChart['series']) > 0)
                <livewire:tallui-pie-chart
                    :series="$earningsChart['series']"
                    :labels="$earningsChart['categories']"
                    :height="220"
                />
            @else
                <x-tallui-empty-state
                    title="No payroll this month"
                    description="Approve a payroll entry this month to see the breakdown."
                    icon="o-chart-pie"
                    size="sm"
                />
            @endif
        </x-tallui-card>
    </div>

    @if (array_sum($loanChart['series']) > 0)
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <x-tallui-card title="Loan Status" icon="o-chart-pie" :shadow="true">
            <livewire:tallui-pie-chart
                :series="$loanChart['series']"
                :labels="$loanChart['categories']"
                :height="220"
            />
        </x-tallui-card>

        {{-- Recent salary payments --}}
        <x-tallui-card title="Recent Salary Payments" icon="o-currency-dollar" :shadow="true" class="lg:col-span-2">
            @forelse ($recentPayments as $payment)
                <x-tallui-list-item
                    :title="$payment->employee?->name ?? '—'"
                    :subtitle="($payment->payrollEntry?->entry_number ?? '—') . ' · ' . ucfirst(str_replace('_', ' ', $payment->method))"
                    :value="number_format($payment->amount, 2)"
                    icon="o-currency-dollar"
                    icon-color="text-success"
                    :separator="!$loop->last"
                    :compact="true"
                >
                    <x-slot:actions>
                        <span class="text-xs text-base-content/50">{{ $payment->paid_at?->format('d M Y') }}</span>
                    </x-slot:actions>
                </x-tallui-list-item>
            @empty
                <x-tallui-empty-state title="No salary payments yet" icon="o-currency-dollar" size="sm" />
            @endforelse
        </x-tallui-card>
    </div>
    @endif

    {{-- ── Recent Payroll Entries ──────────────────────────────────────────────── --}}
    <x-tallui-card title="Recent Payroll Entries" icon="o-document-text" :shadow="true" padding="none">
        <x-slot:actions>
            <x-tallui-button label="View All" :link="route('payroll.entries.index')" class="btn-ghost btn-sm" />
        </x-slot:actions>

        @forelse ($recentEntries as $entry)
            @php
                $statusType = match ($entry->status) {
                    'approved'  => 'success',
                    'cancelled' => 'error',
                    default     => 'warning',
                };
            @endphp
            <x-tallui-list-item
                :title="$entry->entry_number"
                :subtitle="($entry->reference ?: ucfirst($entry->type)) . ' · ' . $entry->date?->format('d M Y')"
                :value="number_format($entry->total_amount, 2)"
                icon="o-document-text"
                icon-color="text-primary"
                :separator="!$loop->last"
                :compact="true"
            >
                <x-slot:actions>
                    <x-tallui-badge :type="$statusType" size="sm">
                        {{ ucfirst($entry->status) }}
                    </x-tallui-badge>
                </x-slot:actions>
            </x-tallui-list-item>
        @empty
            <div class="p-6">
                <x-tallui-empty-state
                    title="No payroll entries"
                    description="Run your first payroll to see it here."
                    icon="o-document-text"
                    size="sm"
                />
            </div>
        @endforelse
    </x-tallui-card>
</div>
