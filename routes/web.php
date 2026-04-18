<?php

declare(strict_types = 1);

use Centrex\Payroll\Http\Livewire\Entities\{EntityFormPage, EntityIndexPage};
use Centrex\Payroll\Http\Livewire\PayrollEntriesPage;
use Centrex\Payroll\Support\PayrollEntityRegistry;
use Illuminate\Support\Facades\Route;

Route::middleware(config('payroll.web_middleware', ['web', 'auth']))
    ->prefix(config('payroll.web_prefix', 'payroll'))
    ->as('payroll.')
    ->group(function (): void {
        Route::get('/', PayrollEntriesPage::class)->name('entries.index');

        foreach (PayrollEntityRegistry::masterDataEntities() as $entity) {
            Route::get("/{$entity}", EntityIndexPage::class)->name("entities.{$entity}.index")->defaults('entity', $entity);
            Route::get("/{$entity}/create", EntityFormPage::class)->name("entities.{$entity}.create")->defaults('entity', $entity);
            Route::get("/{$entity}/{recordId}/edit", EntityFormPage::class)->name("entities.{$entity}.edit")->defaults('entity', $entity);
        }
    });
