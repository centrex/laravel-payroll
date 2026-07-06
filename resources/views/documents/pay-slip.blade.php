<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Pay Slip — {{ $entry->entry_number }}</title>
    <style>
        body { font-family: sans-serif; color: #1a1a1a; font-size: 12px; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 12px; margin-bottom: 16px; }
        .logo { max-height: 60px; max-width: 200px; margin-bottom: 6px; }
        .company-name { font-size: 18px; font-weight: bold; color: #0f766e; }
        .muted { color: #666; font-size: 11px; }
        h1 { font-size: 16px; margin: 0 0 4px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e5e5e5; }
        th { background: #f5f5f5; font-size: 11px; text-transform: uppercase; color: #666; }
        .text-right { text-align: right; }
        .info-table td { border: none; padding: 3px 8px 3px 0; }
        .total-row td { font-weight: bold; border-top: 2px solid #333; }
        .net-pay { background: #ecfdf3; padding: 12px; margin-top: 16px; border-radius: 4px; }
        .net-pay .amount { font-size: 20px; font-weight: bold; color: #027a48; }
        .footer { margin-top: 30px; font-size: 10px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        @if (!empty($company['logo_uri']))
            <img src="{{ $company['logo_uri'] }}" alt="{{ $company['name'] }}" class="logo" />
        @else
            <div class="company-name">{{ $company['name'] }}</div>
        @endif
        @if ($company['address'])
            <div class="muted">{{ $company['address'] }}</div>
        @endif
    </div>

    <h1>Pay Slip</h1>
    <div class="muted">Entry {{ $entry->entry_number }} — {{ $entry->date->format('F Y') }}</div>

    <table class="info-table" style="margin-top: 12px;">
        <tr>
            <td><strong>Employee:</strong> {{ $employee->name }} ({{ $employee->code }})</td>
            <td><strong>Pay Period:</strong> {{ $entry->date->format('d M Y') }}</td>
        </tr>
        <tr>
            <td><strong>Designation:</strong> {{ $employee->designation ?: '—' }}</td>
            <td><strong>Department:</strong> {{ $employee->department ?: '—' }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr><th colspan="2">Earnings</th></tr>
        </thead>
        <tbody>
            @forelse ($earnings as $line)
                <tr>
                    <td>{{ $line->payrollAccount?->name ?? $line->description }}</td>
                    <td class="text-right">{{ number_format($line->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="muted">No earnings recorded.</td></tr>
            @endforelse
            <tr class="total-row">
                <td>Gross Earnings</td>
                <td class="text-right">{{ number_format($earnings->sum('amount'), 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table>
        <thead>
            <tr><th colspan="2">Deductions</th></tr>
        </thead>
        <tbody>
            @forelse ($deductions as $line)
                <tr>
                    <td>{{ $line->payrollAccount?->name ?? $line->description }}</td>
                    <td class="text-right">{{ number_format($line->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="muted">No deductions recorded.</td></tr>
            @endforelse
            <tr class="total-row">
                <td>Total Deductions</td>
                <td class="text-right">{{ number_format($deductions->sum('amount'), 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="net-pay">
        <div class="muted">Net Pay</div>
        <div class="amount">{{ $employee->currency ?? 'BDT' }} {{ number_format($netPay, 2) }}</div>
        <div class="muted" style="margin-top: 4px;">Paid so far: {{ number_format($paid, 2) }} · Outstanding: {{ number_format(max(0, $netPay - $paid), 2) }}</div>
    </div>

    <div class="footer">Generated on {{ now()->format('d M Y, H:i') }} — this is a system-generated document.</div>
</body>
</html>
