<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection') ?? config('database.default');
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'employees', function (Blueprint $table) use ($prefix, $schema): void {
            // Free-text business-unit/company tag — same convention as sbu_code on
            // laravel-accounting's JournalEntry/Invoice and laravel-inventory's documents.
            // Mirrored from laravel-hr's Employee.sbu_code via PayrollSync when synced from
            // there; AccountingSync groups payroll-entry lines by this so one entry spanning
            // multiple companies posts a separate journal entry per company.
            if (!$schema->hasColumn($prefix . 'employees', 'sbu_code')) {
                $table->string('sbu_code', 50)->nullable()->after('department')->index();
            }
        });
    }

    public function down(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection') ?? config('database.default');
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'employees', function (Blueprint $table) use ($prefix, $schema): void {
            if ($schema->hasColumn($prefix . 'employees', 'sbu_code')) {
                $table->dropColumn('sbu_code');
            }
        });
    }
};
