<div>
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
    <div class="grid grid-cols-1 gap-4 mb-6">
        <x-tallui-card title="Loan Status" icon="o-chart-pie" :shadow="true">
            <livewire:tallui-pie-chart
                :series="$loanChart['series']"
                :labels="$loanChart['categories']"
                :height="220"
            />
        </x-tallui-card>
    </div>
@endif
</div>
