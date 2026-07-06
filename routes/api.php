<?php

declare(strict_types = 1);

use Centrex\Payroll\Http\Controllers\Api\{EmployeeLoanController, EntityCrudController, PayrollEntryController, SalaryPaymentController};
use Centrex\Payroll\Support\PayrollEntityRegistry;
use Illuminate\Support\Facades\Route;

Route::middleware(config('payroll.api_middleware', ['api', 'auth:sanctum']))
    ->prefix(config('payroll.api_prefix', 'api/payroll'))
    ->as('payroll.api.')
    ->group(function (): void {
        foreach (PayrollEntityRegistry::masterDataEntities() as $entity) {
            Route::get("/{$entity}", [EntityCrudController::class, 'index'])->defaults('entity', $entity)->name("{$entity}.index");
            Route::post("/{$entity}", [EntityCrudController::class, 'store'])->defaults('entity', $entity)->name("{$entity}.store");
            Route::get("/{$entity}/{recordId}", [EntityCrudController::class, 'show'])->defaults('entity', $entity)->name("{$entity}.show");
            Route::match(['put', 'patch'], "/{$entity}/{recordId}", [EntityCrudController::class, 'update'])->defaults('entity', $entity)->name("{$entity}.update");
            Route::delete("/{$entity}/{recordId}", [EntityCrudController::class, 'destroy'])->defaults('entity', $entity)->name("{$entity}.destroy");
        }

        Route::get('/payroll-entries', [PayrollEntryController::class, 'index'])->name('payroll-entries.index');
        Route::post('/payroll-entries', [PayrollEntryController::class, 'store'])->name('payroll-entries.store');
        Route::get('/payroll-entries/{payrollEntry}', [PayrollEntryController::class, 'show'])->name('payroll-entries.show');
        Route::post('/payroll-entries/{payrollEntry}/approve', [PayrollEntryController::class, 'approve'])->name('payroll-entries.approve');
        Route::delete('/payroll-entries/{payrollEntry}', [PayrollEntryController::class, 'destroy'])->name('payroll-entries.destroy');

        // Salary payments (disbursements) & employee salary ledger
        Route::post('/payroll-entries/{payrollEntry}/pay', [SalaryPaymentController::class, 'store'])->name('salary-payments.store');
        Route::get('/salary-ledger', [SalaryPaymentController::class, 'ledger'])->name('salary-ledger');

        // Employee loans & advances
        Route::get('/loans', [EmployeeLoanController::class, 'index'])->name('loans.index');
        Route::post('/loans', [EmployeeLoanController::class, 'store'])->name('loans.store');
        Route::get('/loans/summary', [EmployeeLoanController::class, 'summary'])->name('loans.summary');
        Route::get('/loans/{employeeLoan}', [EmployeeLoanController::class, 'show'])->name('loans.show');
        Route::post('/loans/{employeeLoan}/approve', [EmployeeLoanController::class, 'approve'])->name('loans.approve');
        Route::post('/loans/{employeeLoan}/repay', [EmployeeLoanController::class, 'repay'])->name('loans.repay');
        Route::post('/loans/{employeeLoan}/cancel', [EmployeeLoanController::class, 'cancel'])->name('loans.cancel');
        Route::get('/loans/{employeeLoan}/repayments', [EmployeeLoanController::class, 'repayments'])->name('loans.repayments');
    });
