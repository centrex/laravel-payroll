<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire;

use Centrex\Payroll\Enums\{LoanStatus, LoanType, RepaymentMethod};
use Centrex\Payroll\Exceptions\{InvalidLoanTransitionException, LoanRepaymentExceedsBalanceException};
use Centrex\Payroll\Facades\Payroll;
use Centrex\Payroll\Models\{Employee, EmployeeLoan};
use Illuminate\Contracts\View\View;
use Livewire\{Component, WithPagination};

class EmployeeLoansPage extends Component
{
    use WithPagination;

    public string $search         = '';
    public string $statusFilter   = '';
    public string $typeFilter     = '';
    public ?int $employeeFilter   = null;

    // Issue loan form
    public bool $showIssueModal   = false;
    public int $employee_id       = 0;
    public string $type           = 'loan';
    public float $amount          = 0;
    public string $repayment_method = 'salary_deduction';
    public int $installments      = 0;
    public float $installment_amount = 0;
    public string $issue_date     = '';
    public string $expected_completion_date = '';
    public string $notes          = '';

    // Repayment modal
    public bool $showRepayModal   = false;
    public ?int $repayLoanId      = null;
    public float $repayAmount     = 0;
    public string $repayMethod    = 'salary_deduction';
    public string $repayDate      = '';
    public string $repayNotes     = '';

    protected array $queryString  = ['search', 'statusFilter', 'typeFilter'];

    public function mount(): void
    {
        $this->issue_date = now()->format('Y-m-d');
        $this->repayDate  = now()->format('Y-m-d');
    }

    public function openIssue(): void
    {
        $this->reset([
            'employee_id', 'type', 'amount', 'repayment_method',
            'installments', 'installment_amount', 'expected_completion_date', 'notes',
        ]);
        $this->type       = 'loan';
        $this->repayment_method = 'salary_deduction';
        $this->issue_date = now()->format('Y-m-d');
        $this->showIssueModal = true;
    }

    public function issueLoan(): void
    {
        $this->validate([
            'employee_id'               => 'required|integer|min:1',
            'type'                      => 'required|in:loan,advance',
            'amount'                    => 'required|numeric|min:0.01',
            'repayment_method'          => 'required|in:salary_deduction,cash,bank_transfer',
            'installments'              => 'nullable|integer|min:1',
            'installment_amount'        => 'nullable|numeric|min:0',
            'issue_date'                => 'required|date',
            'expected_completion_date'  => 'nullable|date|after_or_equal:issue_date',
            'notes'                     => 'nullable|string|max:1000',
        ]);

        Payroll::issueLoan($this->employee_id, [
            'type'                     => $this->type,
            'amount'                   => $this->amount,
            'repayment_method'         => $this->repayment_method,
            'installments'             => $this->installments ?: null,
            'installment_amount'       => $this->installment_amount ?: null,
            'issue_date'               => $this->issue_date,
            'expected_completion_date' => $this->expected_completion_date ?: null,
            'notes'                    => $this->notes ?: null,
        ]);

        $this->dispatch('notify', type: 'success', message: 'Loan/advance issued successfully.');
        $this->showIssueModal = false;
    }

    public function approve(int $id): void
    {
        $loan = EmployeeLoan::findOrFail($id);

        try {
            Payroll::approveLoan($loan);
            $this->dispatch('notify', type: 'success', message: "Loan {$loan->loan_number} approved.");
        } catch (InvalidLoanTransitionException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function cancel(int $id): void
    {
        $loan = EmployeeLoan::findOrFail($id);

        try {
            Payroll::cancelLoan($loan);
            $this->dispatch('notify', type: 'success', message: "Loan {$loan->loan_number} cancelled.");
        } catch (InvalidLoanTransitionException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openRepay(int $id): void
    {
        $loan              = EmployeeLoan::findOrFail($id);
        $this->repayLoanId = $id;
        $this->repayAmount = (float) $loan->installment_amount ?: (float) $loan->outstanding_balance;
        $this->repayMethod = $loan->repayment_method->value;
        $this->repayDate   = now()->format('Y-m-d');
        $this->repayNotes  = '';
        $this->showRepayModal = true;
    }

    public function recordRepayment(): void
    {
        $this->validate([
            'repayLoanId'  => 'required|integer',
            'repayAmount'  => 'required|numeric|min:0.01',
            'repayMethod'  => 'required|in:salary_deduction,cash,bank_transfer',
            'repayDate'    => 'required|date',
            'repayNotes'   => 'nullable|string|max:500',
        ]);

        $loan = EmployeeLoan::findOrFail($this->repayLoanId);

        try {
            Payroll::recordRepayment($loan, [
                'amount'    => $this->repayAmount,
                'method'    => $this->repayMethod,
                'repaid_at' => $this->repayDate,
                'notes'     => $this->repayNotes ?: null,
            ]);

            $this->dispatch('notify', type: 'success', message: 'Repayment recorded successfully.');
            $this->showRepayModal = false;
        } catch (InvalidLoanTransitionException|LoanRepaymentExceedsBalanceException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function render(): View
    {
        $loans = EmployeeLoan::query()
            ->with(['employee', 'repayments'])
            ->when($this->search, fn ($q) => $q->whereHas('employee', fn ($eq) => $eq
                ->where('name', 'like', '%' . $this->search . '%')
                ->orWhere('code', 'like', '%' . $this->search . '%'))
                ->orWhere('loan_number', 'like', '%' . $this->search . '%'))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->employeeFilter, fn ($q) => $q->where('employee_id', $this->employeeFilter))
            ->latest('issue_date')
            ->paginate(config('payroll.per_page.loans', 15));

        $employees = Employee::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('payroll::livewire.employee-loans', [
            'loans'           => $loans,
            'employees'       => $employees,
            'loanTypes'       => LoanType::cases(),
            'loanStatuses'    => LoanStatus::cases(),
            'repaymentMethods'=> RepaymentMethod::cases(),
        ])->layout($layout, ['title' => __('Employee Loans & Advances')]);
    }
}
