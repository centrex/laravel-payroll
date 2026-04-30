<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Controllers\Api;

use Centrex\Payroll\Enums\{LoanType, RepaymentMethod};
use Centrex\Payroll\Exceptions\{InvalidLoanTransitionException, LoanRepaymentExceedsBalanceException};
use Centrex\Payroll\Facades\Payroll;
use Centrex\Payroll\Models\{Employee, EmployeeLoan};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class EmployeeLoanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $loans = EmployeeLoan::query()
            ->with(['employee', 'repayments'])
            ->when($request->employee_id, fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->date_from, fn ($q) => $q->whereDate('issue_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('issue_date', '<=', $request->date_to))
            ->latest('issue_date')
            ->paginate($request->integer('per_page', config('payroll.per_page.loans', 15)));

        return response()->json($loans);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'              => ['required', 'integer', 'exists:' . (new Employee())->getTable() . ',id'],
            'type'                     => ['required', Rule::enum(LoanType::class)],
            'amount'                   => ['required', 'numeric', 'min:0.01'],
            'repayment_method'         => ['nullable', Rule::enum(RepaymentMethod::class)],
            'installments'             => ['nullable', 'integer', 'min:1'],
            'installment_amount'       => ['nullable', 'numeric', 'min:0'],
            'issue_date'               => ['required', 'date'],
            'expected_completion_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency'                 => ['nullable', 'string', 'size:3'],
            'notes'                    => ['nullable', 'string'],
        ]);

        $data['created_by'] = optional($request->user())->getAuthIdentifier();

        $loan = Payroll::issueLoan($data['employee_id'], $data);

        return response()->json(['data' => $loan->load('employee')], 201);
    }

    public function show(EmployeeLoan $employeeLoan): JsonResponse
    {
        return response()->json([
            'data' => $employeeLoan->load(['employee', 'repayments']),
        ]);
    }

    public function approve(Request $request, EmployeeLoan $employeeLoan): JsonResponse
    {
        try {
            $loan = Payroll::approveLoan($employeeLoan, optional($request->user())->getAuthIdentifier());
        } catch (InvalidLoanTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $loan->load(['employee', 'repayments'])]);
    }

    public function repay(Request $request, EmployeeLoan $employeeLoan): JsonResponse
    {
        $data = $request->validate([
            'amount'           => ['required', 'numeric', 'min:0.01'],
            'method'           => ['nullable', Rule::enum(RepaymentMethod::class)],
            'repaid_at'        => ['nullable', 'date'],
            'payroll_entry_id' => ['nullable', 'integer'],
            'notes'            => ['nullable', 'string'],
        ]);

        $data['created_by'] = optional($request->user())->getAuthIdentifier();

        try {
            $repayment = Payroll::recordRepayment($employeeLoan, $data);
        } catch (InvalidLoanTransitionException|LoanRepaymentExceedsBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $repayment,
            'loan' => $employeeLoan->fresh()->load(['employee', 'repayments']),
        ], 201);
    }

    public function cancel(Request $request, EmployeeLoan $employeeLoan): JsonResponse
    {
        try {
            $loan = Payroll::cancelLoan($employeeLoan);
        } catch (InvalidLoanTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $loan]);
    }

    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => ['required', 'integer', 'exists:' . (new Employee())->getTable() . ',id'],
        ]);

        $summary = Payroll::getLoanSummary($request->integer('employee_id'));

        return response()->json(['data' => $summary]);
    }

    public function repayments(EmployeeLoan $employeeLoan): JsonResponse
    {
        return response()->json([
            'data' => $employeeLoan->repayments()->orderByDesc('repaid_at')->get(),
        ]);
    }
}
