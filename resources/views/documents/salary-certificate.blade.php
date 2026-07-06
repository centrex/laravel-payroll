<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Salary Certificate — {{ $employee->name }}</title>
    <style>
        body { font-family: sans-serif; color: #1a1a1a; font-size: 13px; line-height: 1.6; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 12px; margin-bottom: 24px; }
        .logo { max-height: 60px; max-width: 200px; margin-bottom: 6px; }
        .company-name { font-size: 18px; font-weight: bold; color: #0f766e; }
        .muted { color: #666; font-size: 11px; }
        h1 { font-size: 16px; text-align: center; text-decoration: underline; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e5e5e5; }
        th { background: #f5f5f5; font-size: 11px; text-transform: uppercase; color: #666; }
        .text-right { text-align: right; }
        .total-row td { font-weight: bold; border-top: 2px solid #333; }
        .signature { margin-top: 60px; }
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

    <h1>Salary Certificate</h1>

    <p>
        This is to certify that <strong>{{ $employee->name }}</strong> (Employee Code: <strong>{{ $employee->code }}</strong>)
        is currently employed with {{ $company['name'] }}
        @if ($employee->designation) as <strong>{{ $employee->designation }}</strong> @endif
        @if ($employee->department) in the <strong>{{ $employee->department }}</strong> department @endif
        @if ($employee->joining_date) since <strong>{{ $employee->joining_date->format('d F Y') }}</strong> @endif.
    </p>

    <p>The employee's current monthly salary structure is as follows:</p>

    <table>
        <thead>
            <tr><th>Salary Component</th><th class="text-right">Monthly Amount ({{ $employee->currency ?? 'BDT' }})</th></tr>
        </thead>
        <tbody>
            @forelse ($structure as $line)
                <tr>
                    <td>{{ $line->payrollAccount?->name }}</td>
                    <td class="text-right">{{ number_format((float) ($generated->get($line->payroll_account_id)['amount'] ?? 0), 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="muted">No salary structure has been configured for this employee.</td></tr>
            @endforelse
            <tr class="total-row">
                <td>Gross Monthly Salary</td>
                <td class="text-right">{{ number_format($grossMonthly, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 24px;">This certificate is issued upon the employee's request for whatever legitimate purpose it may serve.</p>

    <div class="signature">
        <div>_______________________________</div>
        <div class="muted">Authorized Signatory</div>
        <div class="muted">Date: {{ $issueDate->format('d F Y') }}</div>
    </div>

    <div class="footer">Generated on {{ now()->format('d M Y, H:i') }} — this is a system-generated document.</div>
</body>
</html>
