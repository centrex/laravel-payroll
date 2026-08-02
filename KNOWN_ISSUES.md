# Known Issues — laravel-payroll

_Last checked: 2026-08-02_

## Failing tests

**Fixed** (2026-08-02): `tests/TestCase.php::getPackageProviders()` was missing
`Livewire\LivewireServiceProvider::class`, so `livewire.finder` was unbound when
`PayrollServiceProvider::boot()` called `Livewire::component(...)` during the Orchestra
Testbench boot cycle (`BindingResolutionException: Target class [livewire.finder] does not
exist`), and the entire suite (both `ExampleTest` and `ArchTest`) failed to boot — 0
assertions ever ran. Added the provider; `vendor/bin/pest -p` now passes (3/3, including a
new regression test — see below).

**Fixed** (2026-08-02): `src/Http/Livewire/Entities/EntityFormPage.php::save()` declared
`: \Illuminate\Http\RedirectResponse` and did `return redirect()->route(...)`. Inside a real
Livewire component dispatch, Livewire's `SupportRedirects` hook temporarily rebinds the
container's `redirect` entry so the global `redirect()` helper returns
`Livewire\Features\SupportRedirects\Redirector` instead — a type mismatch PHP enforces as a
fatal `TypeError` the instant the method returns. This crashed **every** payroll
employee/payroll-account save in production:

```text
Centrex\Payroll\Http\Livewire\Entities\EntityFormPage::save(): Return value must be of type
Illuminate\Http\RedirectResponse, Livewire\Features\SupportRedirects\Redirector returned
```

Fixed by using the component's own `$this->redirect(route(...))` instead of returning the
global helper's value (also correctly skips the now-redundant re-render); `save()` now
returns `void`. Added `tests/Feature/EntityFormPageRedirectTest.php` as a regression test —
note it exercises `save()` via direct instantiation rather than `Livewire::test()`, since even
mounting this component through the real Livewire test harness renders its view, which uses
`<x-tallui-*>` components this package doesn't install as a test dependency (supplied by the
host app in production); the container-swap that caused the crash is orthogonal to that and
was confirmed by reading Livewire's `SupportRedirects` source directly. The identical bug
existed in `laravel-hr`'s own `EntityFormPage.php` (same original scaffold) — fixed there too.

**Fixed** (2026-08-02, reported as "employee create not successful and showing no message",
against the sibling `laravel-hr` package but confirmed identical here): `resources/views/livewire/entities/form-page.blade.php`
checked `$errors->first('form.' . $field['name'])` / `$errors->has('form.' . $field['name'])`
for every field, but `EntityFormPage::save()`'s `validator($payload, ...)->validate()` call
validates a plain array keyed by **unprefixed** field names (`code`, `name`, ...) — so any
`ValidationException` it threw populated the error bag under `code`/`name`/etc., never under
`form.code`/`form.name`. The view's lookup never matched, so a failed save (e.g. a duplicate
employee `code`) produced **no visible error at all** — the form just silently didn't save,
with nothing telling the user why. `centrex/laravel-inventory`'s equivalent `EntityFormPage`/
view already used the correct unprefixed form (`$errors->first($field['name'])`) — used as
the reference fix. Added a regression test to `tests/Feature/EntityFormPageRedirectTest.php`.

## Style / static-analysis debt

- `composer test` stops early: `rector --dry-run` (test:refacto) flags **1 file**:
  `src/Http/Controllers/PayrollDocumentController.php:67` (`NullToStrictStringFuncCallArgRector` — wants `base64_encode((string) $contents)`). Run `composer refacto` to apply.
- `vendor/bin/pint --test` — fails on **12 files**: `src/PayrollServiceProvider.php`, `src/Support/PayrollEntityRegistry.php`, `src/Http/Controllers/Api/EmployeeLoanController.php`, `src/Http/Controllers/Api/SalaryPaymentController.php`, and 8 migration files under `database/migrations/` (mostly `new_with_braces`, plus `class_definition`/`braces_position` on the migrations). Run `composer lint` to apply.
- `vendor/bin/phpstan analyse` (level max) — **342 unbaselined errors**. A `phpstan-baseline.neon` exists (19 baselined error groups, 96 lines) and is `include`d in `phpstan.neon.dist`, so these 342 are on top of/beyond the baseline — i.e. new, unaccepted debt. Heaviest concentrations: `src/Payroll.php` (62), `src/Support/AccountingSync.php` (40), `src/Http/Controllers/PayrollDocumentController.php` (23), `src/Http/Controllers/Api/PayrollEntryController.php` (23), `src/Support/PayrollEntityRegistry.php` (18), `src/Http/Livewire/PayrollEntriesPage.php` (18), `src/Http/Livewire/EmployeeLoansPage.php` (17). Common patterns: missing iterable value types on array params/returns, `mixed` values passed where `string`/model-property types are expected, and calls to undefined methods on loosely-typed objects (e.g. `object::newQuery()`).

## TODO / FIXME markers

None found.

## Open GitHub issues

Not checked — the `gh` CLI is not installed in this environment.
