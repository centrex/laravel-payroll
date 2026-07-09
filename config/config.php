<?php

return [

    'base_currency' => env('PAYROLL_CURRENCY', 'BDT'),

    'drivers' => [
        'database' => [
            'connection' => env('PAYROLL_DB_CONNECTION'),
        ],
    ],

    'table_prefix' => env('PAYROLL_TABLE_PREFIX', 'pay_'),

    'web_middleware' => ['web', 'auth'],
    'web_prefix'     => 'payroll',

    'api_middleware' => ['api', 'auth:sanctum'],
    'api_prefix'     => 'api/payroll',

    'per_page' => [
        'entries'   => 15,
        'employees' => 25,
        'loans'     => 15,
    ],

    'admin_roles'          => explode(',', env('PAYROLL_ADMIN_ROLES', 'admin,payroll-admin')),
    'admin_role_attribute' => null,

    // Posts a journal entry to laravel-accounting when a payroll entry is approved. Off by
    // default so payroll works fully standalone until accounting integration is installed
    // and this is turned on. Each PayrollAccount must be mapped to a real GL account
    // (accounting_account_id) and tagged as 'earning' (debit) or 'deduction' (credit).
    'accounting_sync' => [
        'enabled' => env('PAYROLL_ACCOUNTING_SYNC_ENABLED', false),
    ],

    'accounts' => [
        'salaries_payable' => env('PAYROLL_ACCOUNT_SALARIES_PAYABLE', '2250'),
        // Credited when a salary payment is posted and no 'account_code' is given explicitly
        // (same convention as laravel-accounting's recordInvoicePayment/recordBillPayment).
        'default_cash' => env('PAYROLL_ACCOUNT_DEFAULT_CASH', '1000'),
    ],

    // Salary structures: percentage_of_basic lines resolve against whichever Payroll
    // Account has this code. Tax certificates report on whichever Payroll Account has
    // the tax_deduction code below (no separate tax calculation — it just reads that line).
    'salary_structure' => [
        'basic_account_code' => env('PAYROLL_BASIC_ACCOUNT_CODE', 'BASIC'),
    ],

    'tax' => [
        'deduction_account_code' => env('PAYROLL_TAX_ACCOUNT_CODE', 'TAX'),
    ],

];