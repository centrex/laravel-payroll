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
        $connection = config('payroll.drivers.database.connection') ?? config('database.default');

        Schema::connection($connection)->create($prefix . 'salary_structure_lines', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('employee_id')->constrained($prefix . 'employees')->onDelete('cascade');
            $table->foreignId('payroll_account_id')->constrained($prefix . 'payroll_accounts')->onDelete('restrict');
            $table->string('calculation_type', 20)->default('fixed'); // fixed | percentage_of_basic
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('percentage', 8, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['employee_id', 'payroll_account_id']);
            $table->index(['employee_id', 'is_active']);
        });
    }

    public function down(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection') ?? config('database.default');

        Schema::connection($connection)->dropIfExists($prefix . 'salary_structure_lines');
    }
};
