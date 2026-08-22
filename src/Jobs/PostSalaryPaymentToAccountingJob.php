<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Jobs;

use Centrex\Payroll\Jobs\Concerns\TracksAccountingSyncFailure;
use Centrex\Payroll\Models\SalaryPayment;
use Centrex\Payroll\Support\AccountingSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

/**
 * Posts a SalaryPayment's journal entry out-of-band. See PostPayrollEntryToAccountingJob's
 * docblock for why this moved off the request thread and how failures now surface.
 */
class PostSalaryPaymentToAccountingJob implements ShouldQueue
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
        public readonly int $salaryPaymentId,
        public readonly ?string $accountCode = null,
    ) {}

    public function handle(AccountingSync $accountingSync): void
    {
        $payment = SalaryPayment::find($this->salaryPaymentId);

        if (!$payment) {
            return;
        }

        $accountingSync->postSalaryPayment($payment, $this->accountCode);
        $this->clearAccountingSyncError($payment);
    }

    public function failed(\Throwable $exception): void
    {
        $payment = SalaryPayment::find($this->salaryPaymentId);

        if ($payment) {
            $this->recordAccountingSyncError($payment, $exception);
        }
    }
}
