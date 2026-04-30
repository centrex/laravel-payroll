<div>
<x-tallui-notification />

<x-tallui-page-header title="Employee Loans & Advances" subtitle="Manage employee loans, salary advances, and repayments" icon="o-banknotes">
    <x-slot:actions>
        <x-tallui-button :link="route('payroll.entries.index')" icon="o-document-text" class="btn-outline btn-sm">Payroll Entries</x-tallui-button>
        <x-tallui-button wire:click="openIssue" icon="o-plus" class="btn-primary btn-sm">Issue Loan / Advance</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Filters --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="flex-1 min-w-52">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Loan #, employee name or code..." class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-44">
            <x-tallui-form-group label="Status">
                <x-tallui-select wire:model.live="statusFilter" class="select-sm">
                    <option value="">All</option>
                    @foreach($loanStatuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        <div class="w-40">
            <x-tallui-form-group label="Type">
                <x-tallui-select wire:model.live="typeFilter" class="select-sm">
                    <option value="">All</option>
                    @foreach($loanTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

{{-- Loans table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Loan #</th>
                    <th>Employee</th>
                    <th>Type</th>
                    <th>Issue Date</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Outstanding</th>
                    <th>Repayment</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($loans as $loan)
                    <tr class="hover:bg-base-50">
                        <td class="pl-5 font-mono text-sm text-primary font-semibold">{{ $loan->loan_number }}</td>
                        <td>
                            <div class="text-sm font-medium">{{ $loan->employee->name }}</div>
                            <div class="text-xs text-base-content/50">{{ $loan->employee->code }}</div>
                        </td>
                        <td>
                            <x-tallui-badge :type="$loan->type->value === 'advance' ? 'info' : 'primary'" size="sm">
                                {{ $loan->type->label() }}
                            </x-tallui-badge>
                        </td>
                        <td class="text-sm text-base-content/70">{{ $loan->issue_date->format('M d, Y') }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($loan->amount, 2) }}</td>
                        <td class="text-right font-mono text-sm {{ (float)$loan->outstanding_balance > 0 ? 'text-warning font-semibold' : 'text-success' }}">
                            {{ number_format($loan->outstanding_balance, 2) }}
                        </td>
                        <td class="text-sm text-base-content/60">{{ $loan->repayment_method->label() }}</td>
                        <td>
                            @php
                                $badgeType = match($loan->status->value) {
                                    'active'    => 'success',
                                    'completed' => 'neutral',
                                    'cancelled' => 'error',
                                    default     => 'warning',
                                };
                            @endphp
                            <x-tallui-badge :type="$badgeType">{{ $loan->status->label() }}</x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                @if($loan->status->value === 'pending')
                                    <x-tallui-button wire:click="approve({{ $loan->id }})" class="btn-success btn-xs">Approve</x-tallui-button>
                                    <x-tallui-button wire:click="cancel({{ $loan->id }})" class="btn-error btn-xs btn-outline">Cancel</x-tallui-button>
                                @elseif($loan->status->value === 'active')
                                    <x-tallui-button wire:click="openRepay({{ $loan->id }})" icon="o-currency-dollar" class="btn-primary btn-xs">Repay</x-tallui-button>
                                    <x-tallui-button wire:click="cancel({{ $loan->id }})" class="btn-error btn-xs btn-outline">Cancel</x-tallui-button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @if($loan->repayments->count() > 0)
                        <tr class="bg-base-50">
                            <td colspan="9" class="px-10 pb-2 pt-0">
                                <div class="text-xs text-base-content/50 uppercase font-semibold mb-1">Repayment History ({{ $loan->repayments->count() }})</div>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($loan->repayments->sortByDesc('repaid_at') as $repayment)
                                        <div class="badge badge-ghost gap-1 text-xs">
                                            {{ $repayment->repaid_at->format('M d, Y') }}
                                            &mdash;
                                            <span class="font-mono font-semibold">{{ number_format($repayment->amount, 2) }}</span>
                                            ({{ $repayment->method->label() }})
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="9">
                            <x-tallui-empty-state title="No loans or advances found" description="Issue a new loan or salary advance to an employee to get started." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $loans->links() }}</div>
</x-tallui-card>

{{-- Issue Loan Modal --}}
<x-tallui-modal id="issue-loan-modal" title="Issue Loan / Advance" icon="o-banknotes" size="lg">
    <x-slot:trigger>
        <span x-effect="if ($wire.showIssueModal) $dispatch('open-modal', 'issue-loan-modal'); else $dispatch('close-modal', 'issue-loan-modal')"></span>
    </x-slot:trigger>

    <form wire:submit.prevent="issueLoan" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Employee *" :error="$errors->first('employee_id')">
                <x-tallui-select wire:model="employee_id">
                    <option value="">Select employee</option>
                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->code }} – {{ $employee->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
            <x-tallui-form-group label="Type *" :error="$errors->first('type')">
                <x-tallui-select wire:model="type">
                    @foreach($loanTypes as $loanType)
                        <option value="{{ $loanType->value }}">{{ $loanType->label() }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Amount *" :error="$errors->first('amount')">
                <x-tallui-input type="number" step="0.01" wire:model.lazy="amount" placeholder="0.00" class="text-right" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Repayment Method *" :error="$errors->first('repayment_method')">
                <x-tallui-select wire:model="repayment_method">
                    @foreach($repaymentMethods as $method)
                        <option value="{{ $method->value }}">{{ $method->label() }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="No. of Installments" :error="$errors->first('installments')">
                <x-tallui-input type="number" step="1" min="1" wire:model.lazy="installments" placeholder="e.g. 6" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Installment Amount" :error="$errors->first('installment_amount')">
                <x-tallui-input type="number" step="0.01" wire:model.lazy="installment_amount" placeholder="Auto-calculated if empty" class="text-right" />
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Issue Date *" :error="$errors->first('issue_date')">
                <x-tallui-input type="date" wire:model="issue_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Expected Completion Date" :error="$errors->first('expected_completion_date')">
                <x-tallui-input type="date" wire:model="expected_completion_date" />
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="notes" :rows="2" placeholder="Optional notes..." />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showIssueModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="issueLoan" class="btn-primary">Issue Loan / Advance</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>

{{-- Repayment Modal --}}
<x-tallui-modal id="repay-modal" title="Record Repayment" icon="o-currency-dollar" size="md">
    <x-slot:trigger>
        <span x-effect="if ($wire.showRepayModal) $dispatch('open-modal', 'repay-modal'); else $dispatch('close-modal', 'repay-modal')"></span>
    </x-slot:trigger>

    <form wire:submit.prevent="recordRepayment" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Amount *" :error="$errors->first('repayAmount')">
                <x-tallui-input type="number" step="0.01" wire:model.lazy="repayAmount" placeholder="0.00" class="text-right" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Method *" :error="$errors->first('repayMethod')">
                <x-tallui-select wire:model="repayMethod">
                    @foreach($repaymentMethods as $method)
                        <option value="{{ $method->value }}">{{ $method->label() }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Repayment Date *" :error="$errors->first('repayDate')">
            <x-tallui-input type="date" wire:model="repayDate" />
        </x-tallui-form-group>

        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="repayNotes" :rows="2" placeholder="Optional notes..." />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showRepayModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="recordRepayment" class="btn-primary">Record Repayment</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
