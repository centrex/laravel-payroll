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

        Schema::connection($connection)->create($prefix . 'employee_loans', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->string('loan_number')->unique();
            $table->foreignId('employee_id')->constrained($prefix . 'employees')->onDelete('restrict');
            $table->string('type')->default('loan');             // loan | advance
            $table->string('status')->default('pending');        // pending | active | completed | cancelled
            $table->string('repayment_method')->default('salary_deduction'); // salary_deduction | cash | bank_transfer
            $table->decimal('amount', 18, 2);                   // total approved amount
            $table->decimal('disbursed_amount', 18, 2)->default(0);
            $table->decimal('outstanding_balance', 18, 2)->default(0);
            $table->decimal('installment_amount', 18, 2)->default(0);
            $table->unsignedInteger('installments')->nullable(); // null = flexible/open-ended
            $table->string('currency', 3)->default('BDT');
            $table->date('issue_date');
            $table->date('expected_completion_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index('status');
            $table->index('issue_date');
        });

        Schema::connection($connection)->create($prefix . 'employee_loan_repayments', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('employee_loan_id')->constrained($prefix . 'employee_loans')->onDelete('restrict');
            $table->foreignId('payroll_entry_id')->nullable()->constrained($prefix . 'payroll_entries')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('method')->default('salary_deduction'); // salary_deduction | cash | bank_transfer
            $table->date('repaid_at');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('employee_loan_id');
            $table->index('repaid_at');
        });
    }

    public function down(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists($prefix . 'employee_loan_repayments');
        Schema::connection($connection)->dropIfExists($prefix . 'employee_loans');
    }
};
