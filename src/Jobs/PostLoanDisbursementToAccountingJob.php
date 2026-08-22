<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Jobs;

use Centrex\Payroll\Jobs\Concerns\TracksAccountingSyncFailure;
use Centrex\Payroll\Models\EmployeeLoan;
use Centrex\Payroll\Support\AccountingSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

/**
 * Posts an EmployeeLoan disbursement's journal entry out-of-band. See
 * PostPayrollEntryToAccountingJob's docblock for why this moved off the request thread and
 * how failures now surface.
 */
class PostLoanDisbursementToAccountingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TracksAccountingSyncFailure;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $employeeLoanId,
        public readonly ?string $cashAccountCode = null,
    ) {}

    public function handle(AccountingSync $accountingSync): void
    {
        $loan = EmployeeLoan::find($this->employeeLoanId);

        if (!$loan) {
            return;
        }

        $accountingSync->postLoanDisbursement($loan, $this->cashAccountCode);
        $this->clearAccountingSyncError($loan);
    }

    public function failed(\Throwable $exception): void
    {
        $loan = EmployeeLoan::find($this->employeeLoanId);

        if ($loan) {
            $this->recordAccountingSyncError($loan, $exception);
        }
    }
}
