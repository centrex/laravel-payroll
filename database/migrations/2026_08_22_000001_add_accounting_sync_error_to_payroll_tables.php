<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounting_sync_error surfaces a queued AccountingSync post failure (e.g. an unmapped GL
 * account) that would otherwise be silent — set by the posting Job's failed() hook, cleared
 * on the next successful post/retry. Posting moved off the request thread (previously it ran
 * inline inside the same DB::transaction() as the approval/disbursement/payment/repayment
 * itself, so a posting failure rolled back the whole action and the user saw it immediately;
 * async means that visibility has to come from somewhere else, which is this column plus the
 * UI banner + retry action that reads it).
 */
return new class extends Migration
{
    private const TABLES = ['payroll_entries', 'salary_payments', 'employee_loans', 'employee_loan_repayments'];

    public function up(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        foreach (self::TABLES as $tableName) {
            $schema->table($prefix . $tableName, function (Blueprint $table) use ($prefix, $schema, $tableName): void {
                if (!$schema->hasColumn($prefix . $tableName, 'accounting_sync_error')) {
                    $table->text('accounting_sync_error')->nullable()->after('journal_entry_id');
                }
            });
        }
    }

    public function down(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        foreach (self::TABLES as $tableName) {
            $schema->table($prefix . $tableName, function (Blueprint $table) use ($prefix, $schema, $tableName): void {
                if ($schema->hasColumn($prefix . $tableName, 'accounting_sync_error')) {
                    $table->dropColumn('accounting_sync_error');
                }
            });
        }
    }
};
