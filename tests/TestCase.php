<?php

declare(strict_types = 1);

namespace Centrex\Payroll\Tests;

use Centrex\Payroll\PayrollServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'Centrex\\Payroll\\Database\\Factories\\' . class_basename($modelName) . 'Factory',
        );
    }

    #[\Override]
    protected function getPackageProviders($app)
    {
        return [
            PayrollServiceProvider::class,
        ];
    }

    #[\Override]
    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');

        /*
        $migration = include __DIR__.'/../database/migrations/create_laravel-payroll_table.php.stub';
        $migration->up();
        */
    }
}
