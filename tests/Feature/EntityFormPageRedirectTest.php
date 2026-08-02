<?php

declare(strict_types = 1);

use Centrex\Payroll\Http\Livewire\Entities\EntityFormPage;
use Centrex\Payroll\Models\Employee;
use Illuminate\Support\Facades\{Artisan, Route};

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'testing']);

    // payroll.web_enabled defaults to false in tests/TestCase.php, so the real
    // "payroll.entities.{entity}.edit" route isn't registered — define a stand-in
    // so EntityFormPage::save()'s $this->redirect(route(...)) has somewhere to resolve to.
    Route::get('/test/payroll/employees/{recordId}/edit', fn () => 'ok')->name('payroll.entities.employees.edit');
});

it('saves a new entity via EntityFormPage::save() without throwing', function (): void {
    // Regression test for a production bug: save() used to `return redirect()->route(...)`
    // declared as `: \Illuminate\Http\RedirectResponse`. Inside a real Livewire component
    // dispatch, Livewire's SupportRedirects hook temporarily rebinds the container's
    // `redirect` entry so the global redirect() helper returns
    // Livewire\Features\SupportRedirects\Redirector instead of Illuminate\Http\RedirectResponse
    // — a mismatch PHP enforces as a fatal TypeError the instant the method returns. This
    // crashed every payroll employee/payroll-account save in production:
    // "Centrex\Payroll\Http\Livewire\Entities\EntityFormPage::save(): Return value must be of
    // type Illuminate\Http\RedirectResponse, Livewire\Features\SupportRedirects\Redirector
    // returned". Fixed by using the component's own $this->redirect() instead of the global
    // helper's return value — this also correctly skips the now-redundant re-render.
    //
    // We instantiate directly (not via Livewire::test()) because even mounting this
    // component through the real Livewire test harness renders its view, which uses
    // <x-tallui-*> components this package doesn't install as a test dependency (they're
    // supplied by the host app in production). Livewire's container-swap that caused the
    // original crash is orthogonal to that — the crash happened inside save() itself,
    // enforced by PHP's own return-type check, before any rendering occurs.
    $component = new EntityFormPage;
    $component->mount('employees');
    $component->form['code'] = 'EMP-TEST-001';
    $component->form['name'] = 'Test Employee';

    $component->save();

    expect(Employee::query()->where('code', 'EMP-TEST-001')->exists())->toBeTrue();
});

it('exposes validation errors under the plain field name, not a "form." prefix', function (): void {
    // Regression test for a silent-failure bug: the view checks
    // $errors->first($field['name']) (e.g. $errors->first('code')), but save()'s validator
    // call validates a plain $payload array keyed by unprefixed field names — so the
    // resulting ValidationException's error bag is ALSO keyed unprefixed ('code'), not
    // 'form.code'. The view previously checked $errors->first('form.' . $field['name']),
    // which never matched, so a failed save (e.g. duplicate code) showed no error at all —
    // the form just silently didn't save, with nothing telling the user why.
    Employee::query()->create(['code' => 'EMP-DUP', 'name' => 'Existing Employee']);

    $component = new EntityFormPage;
    $component->mount('employees');
    $component->form['code'] = 'EMP-DUP';
    $component->form['name'] = 'Another Employee';

    try {
        $component->save();
        $this->fail('Expected a ValidationException for the duplicate code.');
    } catch (Illuminate\Validation\ValidationException $e) {
        expect($e->errors())->toHaveKey('code');
    }

    expect(Employee::query()->where('name', 'Another Employee')->exists())->toBeFalse();
});

it('form-page.blade.php checks errors under the plain field name', function (): void {
    $view = file_get_contents(__DIR__ . '/../../resources/views/livewire/entities/form-page.blade.php');

    expect($view)->not->toContain("errors->first('form.'")
        ->not->toContain("errors->has('form.'");
});
