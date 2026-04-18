<?php

declare(strict_types = 1);

use Centrex\Payroll\Http\Controllers\Api\{EntityCrudController, PayrollEntryController};
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
    });
