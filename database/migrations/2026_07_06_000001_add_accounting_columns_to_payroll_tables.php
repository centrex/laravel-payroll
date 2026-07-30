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

        $schema->table($prefix . 'payroll_accounts', function (Blueprint $table) use ($prefix, $schema): void {
            if (!$schema->hasColumn($prefix . 'payroll_accounts', 'accounting_account_id')) {
                $table->unsignedBigInteger('accounting_account_id')->nullable()->after('code');
            }

            if (!$schema->hasColumn($prefix . 'payroll_accounts', 'component_type')) {
                // 'earning' posts as a debit (salary expense); 'deduction' posts as a
                // credit (a liability/recovery, e.g. tax or provident fund withheld).
                $table->string('component_type', 20)->default('earning')->after('accounting_account_id');
            }
        });

        $schema->table($prefix . 'payroll_entries', function (Blueprint $table) use ($prefix, $schema): void {
            if (!$schema->hasColumn($prefix . 'payroll_entries', 'journal_entry_id')) {
                $table->unsignedBigInteger('journal_entry_id')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'payroll_accounts', function (Blueprint $table) use ($prefix, $schema): void {
            $columns = array_filter([
                $schema->hasColumn($prefix . 'payroll_accounts', 'accounting_account_id') ? 'accounting_account_id' : null,
                $schema->hasColumn($prefix . 'payroll_accounts', 'component_type') ? 'component_type' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        $schema->table($prefix . 'payroll_entries', function (Blueprint $table) use ($prefix, $schema): void {
            if ($schema->hasColumn($prefix . 'payroll_entries', 'journal_entry_id')) {
                $table->dropColumn('journal_entry_id');
            }
        });
    }
};
