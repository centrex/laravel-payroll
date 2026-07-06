<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Http\Livewire;

use Centrex\Payroll\Facades\Payroll;
use Centrex\Payroll\Models\{Employee, PayrollAccount, PayrollEntry, PayrollEntryLine};
use Centrex\Payroll\Support\AccountingSync;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\{Component, WithPagination};

class PayrollEntriesPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $typeFilter = '';

    public bool $showModal = false;

    public string $date = '';

    public string $type = 'salary';

    public string $reference = '';

    public string $description = '';

    public array $lines = [];

    public ?int $structureEmployeeId = null;

    public ?int $commissionEmployeeId = null;

    public ?int $commissionAccountId = null;

    public string $commissionFrom = '';

    public string $commissionTo = '';

    protected array $queryString = ['search', 'statusFilter', 'typeFilter'];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
        $this->commissionFrom = now()->startOfMonth()->format('Y-m-d');
        $this->commissionTo = now()->endOfMonth()->format('Y-m-d');
        $this->addLine();
    }

    public function addLine(): void
    {
        $this->lines[] = [
            'employee_id'        => '',
            'payroll_account_id' => '',
            'amount'             => 0,
            'description'        => '',
            'reference'          => '',
        ];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /** Appends this employee's salary structure as ready-made lines, skipping zero-value ones. */
    public function addFromStructure(): void
    {
        if (!$this->structureEmployeeId) {
            return;
        }

        $generated = Payroll::generatePayrollLinesFromStructure($this->structureEmployeeId);

        if ($generated === []) {
            $this->dispatch('notify', type: 'warning', message: 'This employee has no salary structure set up yet.');

            return;
        }

        // Drop the single blank starter line before appending, so we don't leave an empty row.
        $this->lines = array_values(array_filter(
            $this->lines,
            fn (array $line): bool => $line['employee_id'] !== '' || $line['payroll_account_id'] !== '',
        ));

        foreach ($generated as $line) {
            if ((float) $line['amount'] === 0.0) {
                continue;
            }

            $this->lines[] = [
                'employee_id'        => $this->structureEmployeeId,
                'payroll_account_id' => $line['payroll_account_id'],
                'amount'             => $line['amount'],
                'description'        => '',
                'reference'          => '',
            ];
        }

        if ($this->lines === []) {
            $this->addLine();
        }

        $this->dispatch('notify', type: 'success', message: 'Salary structure lines added.');
    }

    /** Calculates the employee's sales commission for the period and appends it as a line. */
    public function addCommissionLine(): void
    {
        $this->validate([
            'commissionEmployeeId' => 'required|integer',
            'commissionAccountId'  => 'required|integer',
            'commissionFrom'       => 'required|date',
            'commissionTo'         => 'required|date|after_or_equal:commissionFrom',
        ], [], [
            'commissionEmployeeId' => 'employee',
            'commissionAccountId'  => 'payroll account',
            'commissionFrom'       => 'period start',
            'commissionTo'         => 'period end',
        ]);

        $amount = Payroll::calculateSalesCommission($this->commissionEmployeeId, $this->commissionFrom, $this->commissionTo);

        if ($amount <= 0) {
            $this->dispatch('notify', type: 'warning', message: 'No commission earned for this employee in the selected period (check their user_id and commission_rate are set).');

            return;
        }

        // Drop the single blank starter line before appending, so we don't leave an empty row.
        $this->lines = array_values(array_filter(
            $this->lines,
            fn (array $line): bool => $line['employee_id'] !== '' || $line['payroll_account_id'] !== '',
        ));

        $this->lines[] = [
            'employee_id'        => $this->commissionEmployeeId,
            'payroll_account_id' => $this->commissionAccountId,
            'amount'             => $amount,
            'description'        => 'Sales commission ' . $this->commissionFrom . ' to ' . $this->commissionTo,
            'reference'          => '',
        ];

        $this->dispatch('notify', type: 'success', message: 'Commission of ' . number_format($amount, 2) . ' added.');
    }

    public function openCreate(): void
    {
        $this->reset(['reference', 'description', 'lines', 'structureEmployeeId', 'commissionEmployeeId', 'commissionAccountId']);
        $this->date = now()->format('Y-m-d');
        $this->type = 'salary';
        $this->addLine();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'date'                       => 'required|date',
            'type'                       => 'required|string|in:salary,bonus,deduction,tax,adjustment',
            'lines'                      => 'required|array|min:1',
            'lines.*.employee_id'        => 'required|integer',
            'lines.*.payroll_account_id' => 'required|integer',
            'lines.*.amount'             => 'required|numeric|gt:0',
            'lines.*.description'        => 'nullable|string|max:500',
            'lines.*.reference'          => 'nullable|string|max:255',
        ]);

        DB::transaction(function (): void {
            $entry = PayrollEntry::create([
                'date'          => $this->date,
                'reference'     => $this->reference ?: null,
                'description'   => $this->description ?: null,
                'currency'      => config('payroll.base_currency', 'BDT'),
                'type'          => $this->type,
                'exchange_rate' => 1,
                'status'        => 'draft',
            ]);

            foreach ($this->lines as $line) {
                PayrollEntryLine::create([
                    'payroll_entry_id'   => $entry->id,
                    'employee_id'        => $line['employee_id'],
                    'payroll_account_id' => $line['payroll_account_id'],
                    'amount'             => $line['amount'],
                    'description'        => $line['description'] ?: null,
                    'reference'          => $line['reference'] ?: null,
                ]);
            }
        });

        $this->dispatch('notify', type: 'success', message: 'Payroll entry recorded successfully.');
        $this->showModal = false;
        $this->reset(['reference', 'description', 'lines', 'structureEmployeeId', 'commissionEmployeeId', 'commissionAccountId']);
        $this->date = now()->format('Y-m-d');
        $this->type = 'salary';
        $this->addLine();
    }

    public function approve(int $id): void
    {
        $entry = PayrollEntry::findOrFail($id);

        if ($entry->status !== 'draft') {
            $this->dispatch('notify', type: 'warning', message: 'Only draft payroll entries can be approved.');

            return;
        }

        try {
            DB::transaction(function () use ($entry): void {
                $entry->update([
                    'status'      => 'approved',
                    'approved_at' => now(),
                ]);

                app(AccountingSync::class)->postPayrollEntry($entry);
            });
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('notify', type: 'success', message: "Payroll {$entry->entry_number} approved.");
    }

    public function getTotalProperty(): float
    {
        return collect($this->lines)->sum(fn (array $line): float => (float) ($line['amount'] ?? 0));
    }

    public function render(): View
    {
        $entries = PayrollEntry::query()
            ->with(['lines.employee', 'lines.payrollAccount'])
            ->when($this->search, fn ($query) => $query->where(function ($query): void {
                $query->where('entry_number', 'like', '%' . $this->search . '%')
                    ->orWhere('reference', 'like', '%' . $this->search . '%')
                    ->orWhere('description', 'like', '%' . $this->search . '%');
            }))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->typeFilter, fn ($query) => $query->where('type', $this->typeFilter))
            ->latest('date')
            ->paginate(config('payroll.per_page.entries', 15));

        $accounts = PayrollAccount::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $employees = Employee::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('payroll::livewire.payroll-entries', [
            'entries'   => $entries,
            'accounts'  => $accounts,
            'employees' => $employees,
        ])->layout($layout, ['title' => __('Payroll')]);
    }
}
