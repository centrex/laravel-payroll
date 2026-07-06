<div>
<x-tallui-notification />

<x-tallui-page-header title="Employee Salary Ledger" subtitle="Net payable, paid, and outstanding salary per employee" icon="o-banknotes">
    <x-slot:actions>
        @can('payroll.entries.view')
            <x-tallui-button :link="route('payroll.entries.index')" icon="o-document-text" class="btn-outline btn-sm">Payroll Entries</x-tallui-button>
        @endcan
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

@if ($ledger === null)
    <x-tallui-card padding="none">
        <x-tallui-empty-state title="Select an employee" description="Choose an employee above to see their salary ledger." />
    </x-tallui-card>
@else
    <div class="stats shadow w-full mb-4">
        <x-tallui-stat title="Total Net Payable" :value="number_format($ledger['total_net_payable'], 2)" icon="o-calculator" />
        <x-tallui-stat title="Total Paid" :value="number_format($ledger['total_paid'], 2)" icon="o-check-circle" icon-color="text-success" />
        <x-tallui-stat title="Outstanding" :value="number_format($ledger['total_outstanding'], 2)"
            icon="o-exclamation-circle" :icon-color="$ledger['total_outstanding'] > 0 ? 'text-warning' : 'text-success'" />
    </div>

    <x-tallui-card title="Documents" icon="o-document-arrow-down" :shadow="true" class="mb-4">
        <div class="flex flex-wrap gap-4 items-end">
            <x-tallui-button :link="route('payroll.documents.salary-certificate', ['employee' => $employeeId])" icon="o-identification" class="btn-outline btn-sm" :no-wire-navigate="true">
                Salary Certificate
            </x-tallui-button>

            <form action="{{ route('payroll.documents.tax-certificate', ['employee' => $employeeId]) }}" method="GET" class="flex flex-wrap gap-2 items-end">
                <div class="w-36">
                    <x-tallui-form-group label="From">
                        <x-tallui-input type="date" name="from" value="{{ now()->startOfYear()->format('Y-m-d') }}" class="input-sm" />
                    </x-tallui-form-group>
                </div>
                <div class="w-36">
                    <x-tallui-form-group label="To">
                        <x-tallui-input type="date" name="to" value="{{ now()->format('Y-m-d') }}" class="input-sm" />
                    </x-tallui-form-group>
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Tax Deduction Certificate</button>
            </form>

            <form action="{{ route('payroll.documents.yearly-tax-certificate', ['employee' => $employeeId]) }}" method="GET" class="flex flex-wrap gap-2 items-end">
                <div class="w-28">
                    <x-tallui-form-group label="Year">
                        <x-tallui-input type="number" name="year" value="{{ now()->year }}" class="input-sm" />
                    </x-tallui-form-group>
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Yearly Tax Certificate</button>
            </form>
        </div>
    </x-tallui-card>

    <x-tallui-card padding="none">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                        <th class="pl-5">Entry #</th>
                        <th>Date</th>
                        <th class="text-right">Earnings</th>
                        <th class="text-right">Deductions</th>
                        <th class="text-right">Net Payable</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Outstanding</th>
                        <th class="pr-5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($ledger['entries'] as $row)
                        <tr class="hover:bg-base-50">
                            <td class="pl-5 font-mono text-sm text-primary font-semibold">{{ $row['entry_number'] }}</td>
                            <td class="text-sm text-base-content/70">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('M d, Y') }}</td>
                            <td class="text-right font-mono text-sm">{{ number_format($row['earnings'], 2) }}</td>
                            <td class="text-right font-mono text-sm">{{ number_format($row['deductions'], 2) }}</td>
                            <td class="text-right font-mono text-sm font-semibold">{{ number_format($row['net_payable'], 2) }}</td>
                            <td class="text-right font-mono text-sm text-success">{{ number_format($row['paid'], 2) }}</td>
                            <td class="text-right font-mono text-sm {{ $row['outstanding'] > 0 ? 'text-warning font-semibold' : 'text-success' }}">
                                {{ number_format($row['outstanding'], 2) }}
                            </td>
                            <td class="pr-5 text-right">
                                <div class="flex justify-end gap-1">
                                    <x-tallui-button :link="route('payroll.documents.pay-slip', ['payrollEntry' => $row['id'], 'employeeId' => $employeeId])" icon="o-document-arrow-down" class="btn-ghost btn-xs" :no-wire-navigate="true">Pay Slip</x-tallui-button>
                                    @if ($row['outstanding'] > 0)
                                        <x-tallui-button wire:click="openPay({{ $row['id'] }})" icon="o-currency-dollar" class="btn-primary btn-xs">Pay</x-tallui-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-tallui-empty-state title="No approved payroll entries" description="This employee has no approved payroll entries yet." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>
@endif

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
            Entry <span class="font-mono font-semibold">{{ $payEntryNumber }}</span> — outstanding
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
        <x-tallui-button wire:click="recordPayment" class="btn-primary">Record Payment</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
