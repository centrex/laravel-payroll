<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'employee_loans', function (Blueprint $table) use ($prefix, $schema): void {
            if (!$schema->hasColumn($prefix . 'employee_loans', 'journal_entry_id')) {
                $table->unsignedBigInteger('journal_entry_id')->nullable()->after('outstanding_balance');
            }
        });

        $schema->table($prefix . 'employee_loan_repayments', function (Blueprint $table) use ($prefix, $schema): void {
            if (!$schema->hasColumn($prefix . 'employee_loan_repayments', 'journal_entry_id')) {
                $table->unsignedBigInteger('journal_entry_id')->nullable()->after('amount');
            }
        });
    }

    public function down(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'employee_loans', function (Blueprint $table) use ($prefix, $schema): void {
            if ($schema->hasColumn($prefix . 'employee_loans', 'journal_entry_id')) {
                $table->dropColumn('journal_entry_id');
            }
        });

        $schema->table($prefix . 'employee_loan_repayments', function (Blueprint $table) use ($prefix, $schema): void {
            if ($schema->hasColumn($prefix . 'employee_loan_repayments', 'journal_entry_id')) {
                $table->dropColumn('journal_entry_id');
            }
        });
    }
};
