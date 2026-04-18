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

        Schema::connection($connection)->create($prefix . 'employees', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->string('employment_type')->default('full_time');
            $table->date('joining_date')->nullable();
            $table->decimal('monthly_salary', 18, 2)->default(0);
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->integer('payment_terms')->default(30);
            $table->boolean('is_active')->default(true);
            $table->nullableMorphs('modelable');
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index(['department', 'is_active']);
        });

        Schema::connection($connection)->create($prefix . 'payroll_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->boolean('is_active')->default(true);
            $table->text('particulars')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['code', 'is_active']);
        });

        Schema::connection($connection)->create($prefix . 'payroll_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_number')->unique();
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->string('type');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['date', 'status']);
            $table->index('entry_number');
        });

        Schema::connection($connection)->create($prefix . 'payroll_entry_lines', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained($prefix . 'payroll_entries')->onDelete('cascade');
            $table->foreignId('employee_id')->nullable()->constrained($prefix . 'employees')->nullOnDelete();
            $table->foreignId('payroll_account_id')->constrained($prefix . 'payroll_accounts')->onDelete('restrict');
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index('payroll_entry_id');
            $table->index('payroll_account_id');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists($prefix . 'payroll_entry_lines');
        Schema::connection($connection)->dropIfExists($prefix . 'payroll_entries');
        Schema::connection($connection)->dropIfExists($prefix . 'payroll_accounts');
        Schema::connection($connection)->dropIfExists($prefix . 'employees');
    }
};
