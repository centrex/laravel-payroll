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

];