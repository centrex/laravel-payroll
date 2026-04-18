<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Commands;

use Illuminate\Console\Command;

class PayrollCommand extends Command
{
    public $signature = 'laravel-payroll';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
