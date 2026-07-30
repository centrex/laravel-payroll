<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Controllers\Api;

use Centrex\Payroll\Facades\Payroll;
use Centrex\Payroll\Models\{Employee, PayrollEntry, SalaryPayment};
use Centrex\Payroll\Support\AccountingSync;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class SalaryPaymentController extends Controller
{
    public function store(Request $request, PayrollEntry $payrollEntry): JsonResponse
    {
        $data = $request->validate([
            'employee_id'  => ['required', 'integer', 'exists:' . (new Employee)->getTable() . ',id'],
            'amount'       => ['required', 'numeric', 'min:0.01'],
            'method'       => ['nullable', 'string', 'in:cash,bank_transfer,mobile_banking,cheque'],
            'paid_at'      => ['nullable', 'date'],
            'reference'    => ['nullable', 'string', 'max:200'],
            'notes'        => ['nullable', 'string'],
            'account_code' => ['nullable', 'string'],
        ]);

        $data['created_by'] = optional($request->user())->getAuthIdentifier();

        try {
            $payment = DB::transaction(function () use ($payrollEntry, $data): SalaryPayment {
                $payment = Payroll::recordSalaryPayment($payrollEntry, (int) $data['employee_id'], $data);
                app(AccountingSync::class)->postSalaryPayment($payment, $data['account_code'] ?? null);

                return $payment;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $payment->load(['employee', 'payrollEntry'])], 201);
    }

    public function ledger(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => ['required', 'integer', 'exists:' . (new Employee)->getTable() . ',id'],
        ]);

        $ledger = Payroll::getEmployeeSalaryLedger($request->integer('employee_id'));

        return response()->json(['data' => $ledger]);
    }
}
