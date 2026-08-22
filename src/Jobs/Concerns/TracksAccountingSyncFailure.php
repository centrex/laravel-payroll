<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Jobs\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared by the four *ToAccountingJob classes: persists AccountingSync::post*()'s
 * "unmapped GL account" (or any other) failure onto the record itself, since queuing the
 * post means a thrown \RuntimeException no longer rolls back the triggering
 * approval/disbursement/payment/repayment or reaches the user synchronously.
 */
trait TracksAccountingSyncFailure
{
    protected function clearAccountingSyncError(Model $model): void
    {
        if ($model->accounting_sync_error !== null) {
            $model->forceFill(['accounting_sync_error' => null])->saveQuietly();
        }
    }

    protected function recordAccountingSyncError(Model $model, \Throwable $exception): void
    {
        $model->forceFill(['accounting_sync_error' => $exception->getMessage()])->saveQuietly();
    }
}
