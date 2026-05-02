<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Centrex\Payroll\Payroll
 */
class Payroll extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor()
    {
        return \Centrex\Payroll\Payroll::class;
    }
}
