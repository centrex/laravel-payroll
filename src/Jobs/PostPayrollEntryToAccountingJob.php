<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Jobs;

use Centrex\Payroll\Jobs\Concerns\TracksAccountingSyncFailure;
use Centrex\Payroll\Models\PayrollEntry;
use Centrex\Payroll\Support\AccountingSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

/**
 * Posts an approved PayrollEntry's journal entry out-of-band from the approval request.
 * Previously AccountingSync::postPayrollEntry() ran inline inside the same DB::transaction()
 * as the entry's draft->approved update, so an unmapped GL account rolled back the whole
 * approval and the approver saw it right away. Queuing it means approval always succeeds
 * immediately; a posting failure is instead recorded onto the entry via
 * accounting_sync_error (see TracksAccountingSyncFailure) for the UI to surface + retry.
 */
class PostPayrollEntryToAccountingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TracksAccountingSyncFailure;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $payrollEntryId) {}

    public function handle(AccountingSync $accountingSync): void
    {
        $entry = PayrollEntry::find($this->payrollEntryId);

        if (!$entry) {
            return;
        }

        $accountingSync->postPayrollEntry($entry);
        $this->clearAccountingSyncError($entry);
    }

    public function failed(\Throwable $exception): void
    {
        $entry = PayrollEntry::find($this->payrollEntryId);

        if ($entry) {
            $this->recordAccountingSyncError($entry, $exception);
        }
    }
}
