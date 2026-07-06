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
        $connection = config('payroll.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->create($prefix . 'salary_payments', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained($prefix . 'payroll_entries')->onDelete('restrict');
            $table->foreignId('employee_id')->constrained($prefix . 'employees')->onDelete('restrict');
            $table->decimal('amount', 18, 2);
            $table->string('method')->default('bank_transfer'); // cash | bank_transfer | mobile_banking | cheque
            $table->date('paid_at');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['payroll_entry_id', 'employee_id']);
            $table->index('employee_id');
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists($prefix . 'salary_payments');
    }
};
