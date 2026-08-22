<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Jobs;

use Centrex\Payroll\Jobs\Concerns\TracksAccountingSyncFailure;
use Centrex\Payroll\Models\EmployeeLoanRepayment;
use Centrex\Payroll\Support\AccountingSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

/**
 * Posts an EmployeeLoanRepayment's journal entry out-of-band. See
 * PostPayrollEntryToAccountingJob's docblock for why this moved off the request thread and
 * how failures now surface.
 */
class PostLoanRepaymentToAccountingJob implements ShouldQueue
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
        public readonly int $employeeLoanRepaymentId,
        public readonly ?string $accountCode = null,
    ) {}

    public function handle(AccountingSync $accountingSync): void
    {
        $repayment = EmployeeLoanRepayment::find($this->employeeLoanRepaymentId);

        if (!$repayment) {
            return;
        }

        $accountingSync->postLoanRepayment($repayment, $this->accountCode);
        $this->clearAccountingSyncError($repayment);
    }

    public function failed(\Throwable $exception): void
    {
        $repayment = EmployeeLoanRepayment::find($this->employeeLoanRepaymentId);

        if ($repayment) {
            $this->recordAccountingSyncError($repayment, $exception);
        }
    }
}
