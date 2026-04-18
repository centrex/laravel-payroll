<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Concerns;

trait AddTablePrefix
{
    public function getTable(): string
    {
        return config('payroll.table_prefix', 'pay_') . $this->getTableSuffix();
    }

    abstract protected function getTableSuffix(): string;
}
