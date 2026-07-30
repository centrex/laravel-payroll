<x-tallui-stat
    title="Salary Outstanding"
    :value="number_format($salaryOutstanding, 2)"
    icon="o-exclamation-circle"
    :icon-color="$salaryOutstanding > 0 ? 'text-warning' : 'text-success'"
    desc="Net payable, unpaid"
/>
