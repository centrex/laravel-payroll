<div>
<x-tallui-notification />

<x-tallui-page-header title="Employee Salary Structure" subtitle="Recurring salary template per employee — used to auto-fill new payroll entries" icon="o-adjustments-horizontal">
    <x-slot:actions>
        <x-tallui-button :link="route('payroll.entries.index')" icon="o-document-text" class="btn-outline btn-sm">Payroll Entries</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="w-72">
            <x-tallui-form-group label="Employee">
                <x-tallui-select wire:model.live="employeeId" class="select-sm">
                    <option value="">Select an employee…</option>
                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->code }} – {{ $employee->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

@if ($employeeId === null)
    <x-tallui-card padding="none">
        <x-tallui-empty-state title="Select an employee" description="Choose an employee above to view or edit their salary structure." />
    </x-tallui-card>
@else
    <x-tallui-card padding="none">
        <x-slot:actions>
            <x-tallui-button wire:click="openLine" icon="o-plus" class="btn-primary btn-sm">Add Line</x-tallui-button>
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th class="pl-5">Payroll Account</th>
                        <th>Type</th>
                        <th class="text-right">Amount / %</th>
                        <th class="text-right">Resolved Amount</th>
                        <th>Status</th>
                        <th class="pr-5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($structure as $line)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td class="pl-5 text-sm font-medium">{{ $line->payrollAccount->name }} <span class="text-xs text-base-content/50">({{ $line->payrollAccount->code }})</span></td>
                            <td class="text-sm text-base-content/70">
                                {{ $line->calculation_type === 'percentage_of_basic' ? '% of Basic' : 'Fixed' }}
                            </td>
                            <td class="text-right font-mono text-sm">
                                {{ $line->calculation_type === 'percentage_of_basic' ? number_format($line->percentage, 2) . '%' : number_format($line->amount, 2) }}
                            </td>
                            <td class="text-right font-mono text-sm font-semibold">
                                {{ number_format($generated->get($line->payroll_account_id)['amount'] ?? 0, 2) }}
                            </td>
                            <td>
                                <x-tallui-badge :type="$line->is_active ? 'success' : 'neutral'" size="sm">{{ $line->is_active ? 'Active' : 'Inactive' }}</x-tallui-badge>
                            </td>
                            <td class="pr-5">
                                <div class="flex justify-end gap-1">
                                    <x-tallui-button wire:click="openLine({{ $line->payroll_account_id }})" icon="o-pencil-square" class="btn-ghost btn-xs" />
                                    <x-tallui-button wire:click="removeLine({{ $line->payroll_account_id }})" wire:confirm="Remove this line?" icon="o-trash" class="btn-ghost btn-xs text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-tallui-empty-state title="No salary structure lines yet" description="Add a line (e.g. Basic Salary) to get started." icon="o-adjustments-horizontal" size="sm" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($structure->isNotEmpty())
                    <tfoot>
                        <tr class="bg-base-200/50 font-semibold">
                            <td class="pl-5" colspan="3">Total (net of deductions)</td>
                            <td class="text-right font-mono">{{ number_format($totalAmount, 2) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-tallui-card>
@endif

{{-- Add/Edit Line Modal --}}
<x-tallui-modal id="salary-structure-line-modal" title="Salary Structure Line" icon="o-adjustments-horizontal" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showLineModal) $dispatch('open-modal', 'salary-structure-line-modal'); else $dispatch('close-modal', 'salary-structure-line-modal')"
            @modal-closed.window="if ($event.detail === 'salary-structure-line-modal') $wire.showLineModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="saveLine" class="space-y-4">
        <x-tallui-form-group label="Payroll Account *" :error="$errors->first('payrollAccountId')">
            <x-tallui-select wire:model="payrollAccountId">
                <option value="">Select account</option>
                @foreach($payrollAccounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }} ({{ $account->component_type }})</option>
                @endforeach
            </x-tallui-select>
        </x-tallui-form-group>

        <x-tallui-form-group label="Calculation Type *" :error="$errors->first('calculationType')">
            <x-tallui-select wire:model.live="calculationType">
                <option value="fixed">Fixed Amount</option>
                <option value="percentage_of_basic">% of Basic Salary</option>
            </x-tallui-select>
        </x-tallui-form-group>

        @if ($calculationType === 'fixed')
            <x-tallui-form-group label="Amount *" :error="$errors->first('amount')">
                <x-tallui-input type="number" step="0.01" wire:model.lazy="amount" placeholder="0.00" class="text-right" />
            </x-tallui-form-group>
        @else
            <x-tallui-form-group label="Percentage *" :error="$errors->first('percentage')">
                <x-tallui-input type="number" step="0.01" wire:model.lazy="percentage" placeholder="0.00" class="text-right" />
            </x-tallui-form-group>
        @endif

        <div class="flex items-center gap-2">
            <x-tallui-checkbox wire:model="isActive" />
            <span class="text-sm">Active</span>
        </div>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showLineModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="saveLine" class="btn-primary">Save Line</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
