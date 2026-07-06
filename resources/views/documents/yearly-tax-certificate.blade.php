<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Yearly Tax Certificate {{ $year }} — {{ $employee->name }}</title>
    <style>
        body { font-family: sans-serif; color: #1a1a1a; font-size: 13px; line-height: 1.6; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 12px; margin-bottom: 24px; }
        .logo { max-height: 60px; max-width: 200px; margin-bottom: 6px; }
        .company-name { font-size: 18px; font-weight: bold; color: #0f766e; }
        .muted { color: #666; font-size: 11px; }
        h1 { font-size: 16px; text-align: center; text-decoration: underline; margin-bottom: 4px; }
        h2 { font-size: 13px; text-align: center; color: #666; margin-top: 0; margin-bottom: 24px; font-weight: normal; }
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

    <h1>Yearly Tax Certificate</h1>
    <h2>For the Calendar Year {{ $year }}</h2>

    <p>
        This is to certify that the following amounts were paid and tax deducted at source from the salary of
        <strong>{{ $employee->name }}</strong> (Employee Code: <strong>{{ $employee->code }}</strong>)
        during the calendar year <strong>{{ $year }}</strong>.
    </p>

    <table>
        <tbody>
            <tr>
                <td>Total Gross Salary Paid ({{ $year }})</td>
                <td class="text-right">{{ number_format($gross, 2) }}</td>
            </tr>
            <tr>
                <td>Total Tax Deducted at Source ({{ $year }})</td>
                <td class="text-right">{{ number_format($tax_deducted, 2) }}</td>
            </tr>
            <tr class="total-row">
                <td>Total Net Salary Paid ({{ $year }})</td>
                <td class="text-right">{{ number_format($net, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 24px;">This certificate summarizes amounts recorded in approved payroll entries dated within {{ $year }}, for the employee's annual tax filing purposes.</p>

    <div class="signature">
        <div>_______________________________</div>
        <div class="muted">Authorized Signatory</div>
        <div class="muted">Date: {{ now()->format('d F Y') }}</div>
    </div>

    <div class="footer">Generated on {{ now()->format('d M Y, H:i') }} — this is a system-generated document.</div>
</body>
</html>
