<?php

declare(strict_types = 1);

use Centrex\Payroll\Http\Controllers\PayrollDocumentController;
use Centrex\Payroll\Http\Livewire\{EmployeeLoansPage, EmployeeSalaryLedgerPage, EmployeeSalaryStructurePage, PayrollDashboard, PayrollEntriesPage, PayrollEntryShowPage};
use Centrex\Payroll\Http\Livewire\Entities\{EntityFormPage, EntityIndexPage};
use Centrex\Payroll\Support\PayrollEntityRegistry;
use Illuminate\Support\Facades\Route;

Route::middleware(config('payroll.web_middleware', ['web', 'auth']))
    ->prefix(config('payroll.web_prefix', 'payroll'))
    ->as('payroll.')
    ->group(function (): void {
        Route::get('/', PayrollEntriesPage::class)->name('entries.index');
        Route::get('/entries/{payrollEntryId}', PayrollEntryShowPage::class)->name('entries.show');
        Route::get('/dashboard', PayrollDashboard::class)->name('dashboard');
        Route::get('/loans', EmployeeLoansPage::class)->name('loans.index');
        Route::get('/salary-ledger', EmployeeSalaryLedgerPage::class)->name('salary-ledger.index');
        Route::get('/salary-structure', EmployeeSalaryStructurePage::class)->name('salary-structure.index');

        // Documents (PDF)
        Route::get('/entries/{payrollEntry}/employees/{employeeId}/pay-slip', [PayrollDocumentController::class, 'paySlip'])->name('documents.pay-slip');
        Route::get('/employees/{employee}/salary-certificate', [PayrollDocumentController::class, 'salaryCertificate'])->name('documents.salary-certificate');
        Route::get('/employees/{employee}/tax-certificate', [PayrollDocumentController::class, 'taxDeductionCertificate'])->name('documents.tax-certificate');
        Route::get('/employees/{employee}/yearly-tax-certificate', [PayrollDocumentController::class, 'yearlyTaxCertificate'])->name('documents.yearly-tax-certificate');

        foreach (PayrollEntityRegistry::masterDataEntities() as $entity) {
            Route::get("/{$entity}", EntityIndexPage::class)->name("entities.{$entity}.index")->defaults('entity', $entity);
            Route::get("/{$entity}/create", EntityFormPage::class)->name("entities.{$entity}.create")->defaults('entity', $entity);
            Route::get("/{$entity}/{recordId}/edit", EntityFormPage::class)->name("entities.{$entity}.edit")->defaults('entity', $entity);
        }
    });
