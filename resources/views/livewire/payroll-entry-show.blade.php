<div>
<x-tallui-notification />

<x-tallui-page-header title="Payroll {{ $entry->entry_number }}" subtitle="{{ ucfirst($entry->type) }} run — {{ $entry->date->format('M d, Y') }}" icon="o-users">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[['label' => 'Payroll', 'href' => route('payroll.entries.index')], ['label' => $entry->entry_number]]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button :link="route('payroll.entries.index')" icon="o-arrow-left" class="btn-outline btn-sm">Back to List</x-tallui-button>
        @if ($entry->status === 'draft')
            <x-tallui-button wire:click="approve" icon="o-check-circle" class="btn-success btn-sm">Approve</x-tallui-button>
        @endif
    </x-slot:actions>
</x-tallui-page-header>

<div class="stats shadow w-full mb-4">
    <x-tallui-stat title="Total Payroll" :value="number_format((float) $entry->lines->sum('amount'), 2)" icon="o-calculator" />
    <x-tallui-stat title="Employees" :value="$employeeRows->count()" icon="o-identification" />
    <x-tallui-stat title="Status" :value="ucfirst($entry->status)" icon="o-flag"
        :icon-color="$entry->status === 'approved' ? 'text-success' : 'text-base-content/50'" />
</div>

<x-tallui-card title="Payroll Entry" icon="o-document-text" :shadow="true" class="mb-4">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div><span class="text-base-content/50">Reference</span><div class="font-medium">{{ $entry->reference ?: '—' }}</div></div>
        <div><span class="text-base-content/50">Description</span><div class="font-medium">{{ $entry->description ?: '—' }}</div></div>
        <div><span class="text-base-content/50">Currency</span><div class="font-medium">{{ $entry->currency }}</div></div>
        <div><span class="text-base-content/50">Approved At</span><div class="font-medium">{{ $entry->approved_at?->format('M d, Y H:i') ?? '—' }}</div></div>
    </div>
</x-tallui-card>

<x-tallui-card title="Accounting" subtitle="Journal entry posted for this payroll run." icon="o-book-open" :shadow="true" class="mb-4">
    @if ($journalEntry)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><span class="text-base-content/50">Journal Entry #</span><div class="font-mono font-medium">{{ $journalEntry->entry_number }}</div></div>
            <div><span class="text-base-content/50">Date</span><div class="font-medium">{{ \Illuminate\Support\Carbon::parse($journalEntry->date)->format('M d, Y') }}</div></div>
            <div><span class="text-base-content/50">Status</span><div class="font-medium">{{ ucfirst($journalEntry->status instanceof \BackedEnum ? $journalEntry->status->value : (string) $journalEntry->status) }}</div></div>
            @if (Route::has('accounting.journal'))
                <div class="flex items-end">
                    <x-tallui-button :link="route('accounting.journal')" icon="o-arrow-top-right-on-square" class="btn-outline btn-xs">Open Journal</x-tallui-button>
                </div>
            @endif
        </div>
    @elseif ($entry->status === 'draft')
        <p class="text-sm text-base-content/60">Not posted yet — approving this entry will post it to accounting (if accounting sync is enabled).</p>
    @elseif ($entry->accounting_sync_error)
        <x-tallui-alert type="error" title="Accounting sync failed">
            {{ $entry->accounting_sync_error }}
        </x-tallui-alert>
        <x-tallui-button wire:click="retryAccountingSync" spinner="retryAccountingSync" icon="o-arrow-path" class="btn-warning btn-sm mt-3">Retry Sync</x-tallui-button>
    @else
        <p class="text-sm text-base-content/60">No journal entry linked. Accounting sync may be disabled, syncing may still be in progress, or this entry was approved before sync was turned on.</p>
    @endif
</x-tallui-card>

<x-tallui-card title="Per-Employee Breakdown" icon="o-users" :shadow="true" padding="none" class="mb-4">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Employee</th>
                    <th class="text-right">Earnings</th>
                    <th class="text-right">Deductions</th>
                    <th class="text-right">Net Payable</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Outstanding</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($employeeRows as $row)
                    <tr class="even:bg-base-200/50 hover:bg-base-200 align-top">
                        <td class="pl-5">
                            <div class="font-medium text-sm">{{ $row['employee']?->name ?? '—' }}</div>
                            <div class="text-xs text-base-content/50">{{ $row['employee']?->code }}</div>
                            <div class="mt-1 space-y-0.5">
                                @foreach ($row['lines'] as $line)
                                    <div class="text-xs text-base-content/60 flex justify-between gap-3 max-w-xs">
                                        <span>{{ $line->payrollAccount?->name ?? '—' }}
                                            @if ($line->payrollAccount?->component_type === 'deduction')
                                                <span class="text-error">(deduction)</span>
                                            @endif
                                        </span>
                                        <span class="font-mono">{{ number_format((float) $line->amount, 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['earnings'], 2) }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['deductions'], 2) }}</td>
                        <td class="text-right font-mono text-sm font-semibold">{{ number_format($row['net_payable'], 2) }}</td>
                        <td class="text-right font-mono text-sm text-success">{{ number_format($row['paid'], 2) }}</td>
                        <td class="text-right font-mono text-sm {{ $row['outstanding'] > 0 ? 'text-warning font-semibold' : 'text-success' }}">
                            {{ number_format($row['outstanding'], 2) }}
                        </td>
                        <td class="pr-5 text-right">
                            <div class="flex justify-end gap-1">
                                <x-tallui-button :link="route('payroll.documents.pay-slip', ['payrollEntry' => $entry->id, 'employeeId' => $row['employee']?->id])" icon="o-document-arrow-down" class="btn-ghost btn-xs" :no-wire-navigate="true">Slip</x-tallui-button>
                                @if ($entry->status === 'approved' && $row['outstanding'] > 0)
                                    <x-tallui-button wire:click="openPay({{ $row['employee']?->id }})" icon="o-currency-dollar" class="btn-primary btn-xs">Pay</x-tallui-button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-tallui-empty-state title="No lines on this entry" description="This payroll run has no salary lines." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>

<x-tallui-card title="Payments" subtitle="Salary disbursements recorded against this entry." icon="o-banknotes" :shadow="true" padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Date</th>
                    <th>Employee</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th class="text-right">Amount</th>
                    <th class="pr-5">Posted</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($entry->payments as $payment)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5 text-sm text-base-content/70">{{ $payment->paid_at?->format('M d, Y') }}</td>
                        <td class="text-sm">{{ $payment->employee?->name ?? '—' }}</td>
                        <td class="text-sm text-base-content/60">{{ ucfirst(str_replace('_', ' ', $payment->method ?? '')) }}</td>
                        <td class="text-sm text-base-content/60">{{ $payment->reference ?: '—' }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format((float) $payment->amount, 2) }}</td>
                        <td class="pr-5">
                            <x-tallui-badge :type="$payment->journal_entry_id ? 'success' : 'neutral'">
                                {{ $payment->journal_entry_id ? 'Posted' : 'Not posted' }}
                            </x-tallui-badge>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-tallui-empty-state title="No payments yet" description="Record a payment against an employee above once this entry is approved." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>

{{-- Record Payment Modal --}}
<x-tallui-modal id="pay-salary-modal" title="Record Salary Payment" icon="o-currency-dollar" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showPayModal) $dispatch('open-modal', 'pay-salary-modal'); else $dispatch('close-modal', 'pay-salary-modal')"
            @modal-closed.window="if ($event.detail === 'pay-salary-modal') $wire.showPayModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="recordPayment" class="space-y-4">
        <div class="text-sm text-base-content/60">
            {{ $payEmployeeName }} — outstanding
            <span class="font-mono font-semibold">{{ number_format($payOutstanding, 2) }}</span>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Amount *" :error="$errors->first('payAmount')">
                <x-tallui-input type="number" step="0.01" wire:model.lazy="payAmount" placeholder="0.00" class="text-right" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Method *" :error="$errors->first('payMethod')">
                <x-tallui-select wire:model="payMethod">
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cash">Cash</option>
                    <option value="mobile_banking">Mobile Banking</option>
                    <option value="cheque">Cheque</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Payment Date *" :error="$errors->first('payDate')">
            <x-tallui-input type="date" wire:model="payDate" />
        </x-tallui-form-group>

        <x-tallui-form-group label="Reference">
            <x-tallui-input wire:model="payReference" placeholder="e.g. bank transaction ID" />
        </x-tallui-form-group>

        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="payNotes" :rows="2" placeholder="Optional notes..." />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showPayModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="recordPayment" :spinner="'recordPayment'" class="btn-primary">Record Payment</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
