<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Controllers;

use Centrex\Payroll\Facades\Payroll;
use Centrex\Payroll\Models\{Employee, PayrollEntry, PayrollEntryLine};
use Illuminate\Http\{Request, Response};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * Generates payroll PDFs via barryvdh/laravel-dompdf, the same package laravel-erp already
 * uses for invoice/PO PDFs. Soft-dependent: aborts with a clear message if dompdf isn't
 * installed, rather than a fatal "class not found" — payroll otherwise works without it.
 */
class PayrollDocumentController extends Controller
{
    private function assertPdfAvailable(): void
    {
        abort_unless(
            class_exists(\Barryvdh\DomPDF\Facade\Pdf::class),
            501,
            'PDF generation requires barryvdh/laravel-dompdf to be installed.',
        );
    }

    /**
     * Uses the host app's own document branding (company name, address, logo) when
     * App\Support\DocumentLayoutSettings is available (as in laravel-erp), otherwise
     * falls back to plain config so the package still works standalone.
     */
    private function companyInfo(): array
    {
        if (class_exists(\App\Support\DocumentLayoutSettings::class)) {
            $data = \App\Support\DocumentLayoutSettings::data();

            return [
                'name'     => $data['company_name'] ?? config('app.name'),
                'address'  => $data['address'] ?? null,
                'phone'    => $data['phone'] ?? null,
                'email'    => $data['email'] ?? null,
                'logo_uri' => $this->logoDataUri($data['logo_path'] ?? null),
            ];
        }

        return [
            'name'     => config('payroll.company.name', config('app.name')),
            'address'  => config('payroll.company.address'),
            'phone'    => null,
            'email'    => null,
            'logo_uri' => null,
        ];
    }

    /**
     * Embeds the logo as a base64 data URI rather than linking its storage URL — dompdf's
     * enable_remote defaults to false, so a remote/HTTP image src would silently fail to
     * render. A data URI always works regardless of that setting.
     */
    private function logoDataUri(?string $logoPath): ?string
    {
        if (!$logoPath || !Storage::disk('public')->exists($logoPath)) {
            return null;
        }

        $contents = Storage::disk('public')->get($logoPath);
        $mime = Storage::disk('public')->mimeType($logoPath) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    public function paySlip(Request $request, PayrollEntry $payrollEntry, int $employeeId): Response
    {
        $this->assertPdfAvailable();

        $employee = Employee::findOrFail($employeeId);
        $lines = $payrollEntry->lines()->where('employee_id', $employeeId)->with('payrollAccount')->get();

        abort_if($lines->isEmpty(), 404, 'No payroll lines for this employee on this entry.');

        $earnings = $lines->filter(fn (PayrollEntryLine $l): bool => ($l->payrollAccount?->component_type ?? 'earning') !== 'deduction');
        $deductions = $lines->filter(fn (PayrollEntryLine $l): bool => $l->payrollAccount?->component_type === 'deduction');
        $netPay = round((float) $earnings->sum('amount') - (float) $deductions->sum('amount'), 2);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('payroll::documents.pay-slip', [
            'company'    => $this->companyInfo(),
            'employee'   => $employee,
            'entry'      => $payrollEntry,
            'earnings'   => $earnings,
            'deductions' => $deductions,
            'netPay'     => $netPay,
            'paid'       => Payroll::totalPaidForEmployee($payrollEntry, $employeeId),
        ])->setPaper('a4');

        return $pdf->download('payslip-' . $payrollEntry->entry_number . '-' . $employee->code . '.pdf');
    }

    public function salaryCertificate(Request $request, Employee $employee): Response
    {
        $this->assertPdfAvailable();

        $structure = Payroll::getSalaryStructure($employee);
        $generated = collect(Payroll::generatePayrollLinesFromStructure($employee))->keyBy('payroll_account_id');

        $grossMonthly = round((float) $structure
            ->filter(fn ($line): bool => ($line->payrollAccount?->component_type ?? 'earning') !== 'deduction')
            ->sum(fn ($line): float => (float) ($generated->get($line->payroll_account_id)['amount'] ?? 0)), 2);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('payroll::documents.salary-certificate', [
            'company'      => $this->companyInfo(),
            'employee'     => $employee,
            'structure'    => $structure,
            'generated'    => $generated,
            'grossMonthly' => $grossMonthly,
            'issueDate'    => now(),
        ])->setPaper('a4');

        return $pdf->download('salary-certificate-' . $employee->code . '.pdf');
    }

    public function taxDeductionCertificate(Request $request, Employee $employee): Response
    {
        $this->assertPdfAvailable();

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('payroll::documents.tax-deduction-certificate', [
            'company'  => $this->companyInfo(),
            'employee' => $employee,
            'from'     => $validated['from'],
            'to'       => $validated['to'],
            ...$this->taxSummary($employee, $validated['from'], $validated['to']),
        ])->setPaper('a4');

        return $pdf->download('tax-certificate-' . $employee->code . '.pdf');
    }

    public function yearlyTaxCertificate(Request $request, Employee $employee): Response
    {
        $this->assertPdfAvailable();

        $year = (int) $request->query('year', now()->year);
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('payroll::documents.yearly-tax-certificate', [
            'company'  => $this->companyInfo(),
            'employee' => $employee,
            'year'     => $year,
            ...$this->taxSummary($employee, $from, $to),
        ])->setPaper('a4');

        return $pdf->download('yearly-tax-certificate-' . $year . '-' . $employee->code . '.pdf');
    }

    /**
     * Gross earnings, and whatever amount sits on the Payroll Account tagged as the tax
     * deduction (config('payroll.tax.deduction_account_code')) — no separate tax
     * calculation, this just reports on that existing line, per every approved entry
     * in the period.
     *
     * @return array{gross: float, tax_deducted: float, net: float}
     */
    private function taxSummary(Employee $employee, string $from, string $to): array
    {
        $taxCode = (string) config('payroll.tax.deduction_account_code', 'TAX');

        $lines = PayrollEntryLine::query()
            ->where('employee_id', $employee->id)
            ->whereHas('payrollEntry', fn ($q) => $q->where('status', 'approved')->whereBetween('date', [$from, $to]))
            ->with('payrollAccount')
            ->get();

        $gross = (float) $lines
            ->filter(fn (PayrollEntryLine $l): bool => ($l->payrollAccount?->component_type ?? 'earning') !== 'deduction')
            ->sum('amount');

        $taxDeducted = (float) $lines
            ->filter(fn (PayrollEntryLine $l): bool => $l->payrollAccount?->code === $taxCode)
            ->sum('amount');

        return [
            'gross'        => round($gross, 2),
            'tax_deducted' => round($taxDeducted, 2),
            'net'          => round($gross - $taxDeducted, 2),
        ];
    }
}
