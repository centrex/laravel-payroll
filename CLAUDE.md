# CLAUDE.md

## Package Overview

`centrex/laravel-payroll` — Payroll module for Laravel: employees, salary structures, payroll runs (entries), salary disbursement, employee loans/advances, and sales-commission calculation. Livewire UI + REST API. Works fully standalone; optionally integrates with `laravel-accounting` (post payroll to the GL) and `laravel-hr` (mirror HR employees in) and `laravel-inventory` (compute commission from real sales).

Namespace: `Centrex\Payroll\`
Service Provider: `PayrollServiceProvider`
Facade: `Facades\Payroll` → resolves `Centrex\Payroll\Payroll` singleton (also aliased as `laravel-payroll`)

## Commands

Run from inside this directory (`cd laravel-payroll`):

```sh
composer install          # install dependencies
composer test              # full suite: rector dry-run, pint check, phpstan, pest
composer test:unit         # pest tests only
composer test:lint         # pint style check (read-only)
composer test:types        # phpstan static analysis
composer test:refacto      # rector refactor check (read-only)
composer lint               # apply pint formatting
composer refacto            # apply rector refactors
composer analyse             # phpstan (alias)
composer build               # prepare testbench workbench
composer start                # build + serve testbench dev server
```

Run a single test:
```sh
vendor/bin/pest tests/Feature/SomeTest.php
vendor/bin/pest --filter "test name"
```

## Structure

```
src/
  Payroll.php                # Main facade target — loans, salary payments, ledger, structure, commission
  PayrollServiceProvider.php
  Facades/Payroll.php
  Enums/                      # LoanStatus, LoanType, RepaymentMethod
  Exceptions/                 # InvalidLoanTransitionException, InvalidPayrollEntryStatusException,
                               # LoanRepaymentExceedsBalanceException, SalaryPaymentExceedsNetPayableException
  Models/                     # Employee, PayrollAccount, PayrollEntry, PayrollEntryLine,
                               # EmployeeLoan, EmployeeLoanRepayment, SalaryPayment, SalaryStructureLine
  Support/
    PayrollEntityRegistry.php # Generic CRUD entity definitions for Employees / Payroll Accounts
    AccountingSync.php        # Posts an approved PayrollEntry to laravel-accounting
  Http/
    Livewire/                 # PayrollDashboard, PayrollEntriesPage, EmployeeLoansPage,
                               # EmployeeSalaryLedgerPage, EmployeeSalaryStructurePage,
                               # Entities/{EntityIndexPage,EntityFormPage}
    Controllers/
      PayrollDocumentController.php     # Pay slip / salary certificate / tax certificate PDFs (web)
      Api/                              # EntityCrudController, PayrollEntryController,
                                          # SalaryPaymentController, EmployeeLoanController
config/config.php
database/migrations/
routes/
  web.php
  api.php
resources/views/
tests/
workbench/
```

## Core Concepts

- **Employee** — `code`, `name`, `department`, `designation` (plain strings, not FKs), `employment_type`, `joining_date`, `monthly_salary`, bank/emergency-contact fields, `currency`. Optional `user_id` + `commission_rate` (percent) enable sales-commission calculation. `sbu_code` (free-text, nullable) tags which company/business-unit the employee belongs to — mirrored from `laravel-hr`'s `Employee.sbu_code` via `PayrollSync` when synced from there, and constrains which employees can share one `PayrollEntry` (see `AccountingSync` below). Optional `modelable_type`/`modelable_id` polymorphic pair links back to an HR employee when synced from `laravel-hr`.
- **PayrollAccount** — one row per salary component (Basic, House Rent, Conveyance, Medical Allowance, TA/DA, Commission, Mobile Allowance, deductions, tax, etc.). Has a `component_type`: `earning` (debit-normal) or `deduction` (credit-normal), and an optional `accounting_account_id` linking it to a real `laravel-accounting` GL account — required for that component to be postable to accounting.
- **PayrollEntry** — one payroll "run" for **one company** (e.g. a month's salary at a single business unit, a bonus batch). Status: `draft → approved`. Auto-numbered `PAY-YYYYMMDD-NNNNN`. Has many `PayrollEntryLine` (one row per employee × payroll account × amount — this is the actual payslip grid, transposed). If a run needs to cover employees from more than one `sbu_code`, split it into one `PayrollEntry` per company — `AccountingSync` refuses to post an entry whose employees carry more than one distinct `sbu_code`, rather than commingling two companies' salary expense into one journal entry.
- **SalaryStructureLine** — a per-employee recurring template line (`calculation_type`: `fixed` or `percentage_of_basic`), so a new month's entry can be pre-filled instead of re-typing every component every time.
- **EmployeeLoan** / **EmployeeLoanRepayment** — loans and salary advances (`type`: `loan` | `advance`), with installment tracking and repayment history. Repayments can optionally reduce a later `PayrollEntry`'s net payable via `payroll_entry_id`.
- **SalaryPayment** — an actual disbursement against an *approved* `PayrollEntry`, capped at that employee's net payable (earnings − deductions) on that entry, minus whatever's already been paid.

### Status/state machines

| Entity | Flow |
|---|---|
| `PayrollEntry` | `draft` → `approved` (approval posts to accounting if enabled; irreversible in the UI) |
| `EmployeeLoan` (`LoanStatus`) | `Pending` → `Active` → `Completed`; `Pending`/`Active` → `Cancelled` |

## Usage

### Payroll accounts & employees (one-time setup)

Master data is managed via the generic entity CRUD (`/payroll/employees`, `/payroll/payroll-accounts`, or the matching REST endpoints) — no dedicated facade methods for basic CRUD, just `Employee::create()` / `PayrollAccount::create()` directly, or the UI.

```php
use Centrex\Payroll\Models\{Employee, PayrollAccount};

$basic = PayrollAccount::create([
    'code' => 'BASIC', 'name' => 'Basic Salary', 'component_type' => 'earning',
    'currency' => 'BDT', 'is_active' => true,
    'accounting_account_id' => $glSalariesAccountId, // optional, required only to post to accounting
]);

$employee = Employee::create([
    'code' => 'EMP-001', 'name' => 'Jane Doe', 'department' => 'Sales',
    'designation' => 'Sales Executive', 'employment_type' => 'full_time',
    'monthly_salary' => 50000, 'currency' => 'BDT', 'is_active' => true,
    'user_id' => $userId,          // optional — enables sales commission
    'commission_rate' => 0.75,     // percent
]);
```

### Salary structure (recurring components, set once per employee)

```php
use Centrex\Payroll\Facades\Payroll;

Payroll::setSalaryStructureLine($employee, $basic->id, ['calculation_type' => 'fixed', 'amount' => 40000]);
Payroll::setSalaryStructureLine($employee, $rentAccount->id, [
    'calculation_type' => 'percentage_of_basic', 'percentage' => 50, // resolves against whichever line has code = config('payroll.salary_structure.basic_account_code')
]);

Payroll::getSalaryStructure($employee);              // Collection<SalaryStructureLine> with payrollAccount loaded
Payroll::generatePayrollLinesFromStructure($employee); // [['payroll_account_id' => .., 'amount' => ..], ...] ready to use as entry lines
Payroll::removeSalaryStructureLine($employee, $accountId);
```

### Creating & approving a payroll entry (a month's salary run)

```php
use Centrex\Payroll\Models\{PayrollEntry, PayrollEntryLine};
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($employee, $basic) {
    $entry = PayrollEntry::create([
        'date' => '2026-02-28', 'type' => 'salary', 'currency' => 'BDT',
        'exchange_rate' => 1, 'status' => 'draft', 'description' => 'Feb 2026 Salary',
    ]);

    foreach (Payroll::generatePayrollLinesFromStructure($employee) as $line) {
        PayrollEntryLine::create([
            'payroll_entry_id' => $entry->id, 'employee_id' => $employee->id,
            'payroll_account_id' => $line['payroll_account_id'], 'amount' => $line['amount'],
        ]);
    }
});

// Approving posts one balanced journal entry to laravel-accounting (if accounting_sync.enabled):
// every 'earning' PayrollAccount debited by its line total, every 'deduction' credited, and
// the net (earnings − deductions) credited to the salaries-payable account (config('payroll.accounts.salaries_payable')).
// The journal entry's sbu_code is derived from the entry's employees (must all share one, or be unset).
// Throws if any PayrollAccount used on the entry has no accounting_account_id mapped — all-or-nothing.
$entry->update(['status' => 'approved', 'approved_at' => now()]);
app(\Centrex\Payroll\Support\AccountingSync::class)->postPayrollEntry($entry);
```

### Sales commission (optional, requires laravel-inventory)

```php
// Employee::user_id must match Centrex\Inventory\Models\SaleOrder::sales_executive_id.
// Sums subtotal_amount of that employee's non-draft/cancelled/returned orders in the period,
// times commission_rate%. Returns 0 if user_id/commission_rate unset or inventory not installed.
$commission = Payroll::calculateSalesCommission($employee, '2026-02-01', '2026-02-29');
```

### Salary disbursement & ledger

```php
// Net payable = sum('earning' lines) − sum('deduction' lines) for that employee on that entry.
Payroll::netPayableForEmployee($entry, $employee->id);
Payroll::totalPaidForEmployee($entry, $employee->id);

// Throws SalaryPaymentExceedsNetPayableException if amount > outstanding (net payable − already paid).
// Throws InvalidPayrollEntryStatusException unless the entry is 'approved'.
$payment = Payroll::recordSalaryPayment($entry, $employee->id, [
    'amount' => 45000, 'method' => 'bank_transfer', 'paid_at' => '2026-03-01', 'reference' => 'TRX-001',
]);

// Cross-entry ledger for one employee (every approved entry they appear on):
$ledger = Payroll::getEmployeeSalaryLedger($employee);
// ['entries' => [['id','entry_number','date','earnings','deductions','net_payable','paid','outstanding'], ...],
//  'total_net_payable', 'total_paid', 'total_outstanding']

Payroll::getEmployeeSalaryOutstanding($employee); // shortcut for total_outstanding above
```

### Employee loans & advances

```php
use Centrex\Payroll\Enums\LoanType;

$loan = Payroll::issueLoan($employee, [
    'type' => LoanType::Advance->value, 'amount' => 10000,
    'repayment_method' => 'salary_deduction', 'installments' => 5, // installment_amount auto-computed if omitted
    'issue_date' => '2026-02-01',
]);

Payroll::approveLoan($loan, approvedBy: $userId); // Pending -> Active, disburses full amount

// Throws LoanRepaymentExceedsBalanceException if amount > outstanding_balance.
// Auto-completes the loan (status -> Completed) when the balance reaches 0.
Payroll::recordRepayment($loan, ['amount' => 2000, 'repaid_at' => '2026-03-01']);

Payroll::cancelLoan($loan);              // only from Pending or Active
Payroll::getActiveLoans($employeeId);    // Collection<EmployeeLoan>, optionally scoped to one employee
Payroll::getLoanSummary($employee);      // totals: issued/disbursed/repaid/outstanding + counts by status
```

### Documents (PDF, web only)

`GET /payroll/entries/{payrollEntry}/employees/{employeeId}/pay-slip`, `.../employees/{employee}/salary-certificate`, `.../tax-certificate`, `.../yearly-tax-certificate` — rendered by `PayrollDocumentController`, split earning/deduction lines by each line's `PayrollAccount::component_type`. Tax certificates read whichever `PayrollAccount` has the code in `config('payroll.tax.deduction_account_code')` (default `TAX`) — there's no separate tax calculation engine.

## Web UI routes (Livewire)

All routes are under `config('payroll.web_prefix')` (default `payroll`), protected by `web_middleware` (default `['web', 'auth']`), route name prefix `payroll.`:

| Route name | Path | Component |
|---|---|---|
| `payroll.entries.index` | `/payroll` | `PayrollEntriesPage` |
| `payroll.dashboard` | `/payroll/dashboard` | `PayrollDashboard` |
| `payroll.loans.index` | `/payroll/loans` | `EmployeeLoansPage` |
| `payroll.salary-ledger.index` | `/payroll/salary-ledger` | `EmployeeSalaryLedgerPage` |
| `payroll.salary-structure.index` | `/payroll/salary-structure` | `EmployeeSalaryStructurePage` |
| `payroll.entities.{employees\|payroll-accounts}.index` | `/payroll/{entity}` | `EntityIndexPage` |
| `payroll.entities.{employees\|payroll-accounts}.create` | `/payroll/{entity}/create` | `EntityFormPage` |
| `payroll.entities.{employees\|payroll-accounts}.edit` | `/payroll/{entity}/{recordId}/edit` | `EntityFormPage` |
| `payroll.documents.pay-slip` | `/payroll/entries/{payrollEntry}/employees/{employeeId}/pay-slip` | PDF |
| `payroll.documents.salary-certificate` | `/payroll/employees/{employee}/salary-certificate` | PDF |
| `payroll.documents.tax-certificate` | `/payroll/employees/{employee}/tax-certificate` | PDF |
| `payroll.documents.yearly-tax-certificate` | `/payroll/employees/{employee}/yearly-tax-certificate` | PDF |

`PayrollEntriesPage` key actions: `openCreate`, `addLine`/`removeLine`, `addFromStructure` (bulk-fills one employee's structure lines), `addCommissionLine` (computes + appends live commission for a period), `save` (creates draft entry + lines), `approve` (locks + posts to accounting).

## REST API

Base prefix: `api/payroll` (configurable). Default middleware: `['api', 'auth:sanctum']`. Route name prefix `payroll.api.`.

| Method | Endpoint | Action |
|---|---|---|
| GET/POST | `/api/payroll/{employees\|payroll-accounts}` | list / create master data |
| GET/PUT/PATCH/DELETE | `/api/payroll/{entity}/{recordId}` | show / update / delete |
| GET | `/api/payroll/payroll-entries` | list entries |
| POST | `/api/payroll/payroll-entries` | create entry (+ lines) |
| GET | `/api/payroll/payroll-entries/{payrollEntry}` | show entry with lines |
| POST | `/api/payroll/payroll-entries/{payrollEntry}/approve` | approve (posts to accounting) |
| DELETE | `/api/payroll/payroll-entries/{payrollEntry}` | delete (draft only) |
| POST | `/api/payroll/payroll-entries/{payrollEntry}/pay` | record a salary disbursement |
| GET | `/api/payroll/salary-ledger` | employee salary ledger |
| GET | `/api/payroll/loans` | list loans |
| POST | `/api/payroll/loans` | issue a loan/advance |
| GET | `/api/payroll/loans/summary` | loan summary |
| GET | `/api/payroll/loans/{employeeLoan}` | show loan |
| POST | `/api/payroll/loans/{employeeLoan}/approve` | approve + disburse |
| POST | `/api/payroll/loans/{employeeLoan}/repay` | record repayment |
| POST | `/api/payroll/loans/{employeeLoan}/cancel` | cancel (pending/active only) |
| GET | `/api/payroll/loans/{employeeLoan}/repayments` | repayment history |

## Authorization gates

All gates fall back to the `payroll-admin` super-gate, or `$user->hasRole()` against `admin_roles` (default `admin,payroll-admin`).

`payroll.entries.view` / `.manage`, `payroll.employees.view` / `.manage`, `payroll.heads.view` / `.manage` (payroll accounts), `payroll.loans.view` / `.manage` / `.approve`.

## Environment Variables

```env
PAYROLL_CURRENCY=BDT
PAYROLL_DB_CONNECTION=
PAYROLL_TABLE_PREFIX=pay_
PAYROLL_ADMIN_ROLES=admin,payroll-admin

# Off by default — payroll works fully standalone until you turn this on and map every
# PayrollAccount used to a real GL account.
PAYROLL_ACCOUNTING_SYNC_ENABLED=false
PAYROLL_ACCOUNT_SALARIES_PAYABLE=2250
# Debited when an employee loan/advance is disbursed, credited when it's repaid.
PAYROLL_ACCOUNT_EMPLOYEE_LOAN_RECEIVABLE=1450

# percentage_of_basic salary-structure lines resolve against whichever PayrollAccount has this code
PAYROLL_BASIC_ACCOUNT_CODE=BASIC
# Tax certificates read whichever PayrollAccount has this code (no separate tax engine)
PAYROLL_TAX_ACCOUNT_CODE=TAX
```

## Cross-package integration

- **`laravel-accounting`** (optional) — `Support\AccountingSync::postPayrollEntry()` posts one journal entry per approved `PayrollEntry`. Requires `accounting_sync.enabled=true` and every `PayrollAccount` used on the entry to have `accounting_account_id` set; throws `\RuntimeException` (not a silent partial post) if any are unmapped. `AccountingSync::postLoanDisbursement()`/`postLoanRepayment()` post the same way for `EmployeeLoan`/`EmployeeLoanRepayment` (DR/CR `payroll.accounts.employee_loan_receivable`, default code `1450`) — called by the Livewire/API loan-approve and repay actions right after `Payroll::approveLoan()`/`recordRepayment()`, same pattern as `postPayrollEntry()`/`postSalaryPayment()`. A `salary_deduction` repayment debits `salaries_payable` instead of cash, since no cash actually moves.
- **`laravel-hr`** (optional, inbound) — `Centrex\Hr\Support\PayrollSync::syncEmployee()` mirrors an HR `Employee` into this package's `Employee` (`hr.payroll_sync.enabled=true`), linked via `modelable_type`/`modelable_id` here and `payroll_profile_type`/`payroll_profile_id` on the HR side — the same polymorphic-pair pattern `laravel-inventory`'s `ErpIntegration` uses for customers/suppliers.
- **`laravel-inventory`** (optional) — `Payroll::calculateSalesCommission()` reads `Centrex\Inventory\Models\SaleOrder` directly (soft dependency via `class_exists()`) to compute commission from real sales, matched by `Employee::user_id` ⇄ `SaleOrder::sales_executive_id`.

## Conventions

- PHP 8.2+, `declare(strict_types=1)` in all files
- Pest for tests, snake_case test names
- Pint with `laravel` preset
- Rector targeting PHP 8.3 with `CODE_QUALITY`, `DEAD_CODE`, `EARLY_RETURN`, `TYPE_DECLARATION`, `PRIVATIZATION` sets
- PHPStan at level `max` with Larastan
