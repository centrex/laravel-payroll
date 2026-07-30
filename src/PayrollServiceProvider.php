<?php

declare(strict_types = 1);

namespace Centrex\Payroll;

use Illuminate\Support\Facades\{Blade, Gate};
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class PayrollServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        /*
         * Optional methods to load your package assets
         */
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'laravel-payroll');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'payroll');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        $this->registerViteDirective();

        if ((bool) config('payroll.web_enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }

        if ((bool) config('payroll.api_enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }

        $this->registerLivewireComponents();
        $this->registerGates();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('payroll.php'),
            ], 'payroll-config');

            // Publishing the migrations.
            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'laravel-payroll-migrations');

            // Publishing the views.
            /*$this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-payroll'),
            ], 'laravel-payroll-views');*/

            // Publishing assets.
            /*$this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/laravel-payroll'),
            ], 'laravel-payroll-assets');*/

            // Publishing the translation files.
            /*$this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/laravel-payroll'),
            ], 'laravel-payroll-lang');*/

            // Registering package commands.
            // $this->commands([]);
        }
    }

    protected function registerGates(): void
    {
        $abilities = [
            'payroll.entries.view',
            'payroll.entries.manage',
            'payroll.employees.view',
            'payroll.employees.manage',
            'payroll.heads.view',
            'payroll.heads.manage',
            'payroll.loans.view',
            'payroll.loans.manage',
            'payroll.loans.approve',
        ];

        foreach ($abilities as $ability) {
            if (!Gate::has($ability)) {
                Gate::define($ability, function ($user): bool {
                    if (Gate::has('payroll-admin') && Gate::forUser($user)->check('payroll-admin')) {
                        return true;
                    }

                    if (is_object($user) && method_exists($user, 'hasRole')) {
                        return $user->hasRole($this->normalizeAdminRoles(config('payroll.admin_roles', [])));
                    }

                    return false;
                });
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAdminRoles(mixed $roles): array
    {
        if (is_array($roles)) {
            return array_values(array_filter(array_map('strval', $roles)));
        }

        if (!is_string($roles) || trim($roles) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $roles))));
    }

    private function registerViteDirective(): void
    {
        Blade::directive('payrollVite', fn (): string => sprintf(
            '<?php echo \\Centrex\\TallUi\\Support\\PackageVite::render(%s, %s, %s); ?>',
            var_export(dirname(__DIR__), true),
            var_export('payroll.hot', true),
            var_export(['resources/js/app.js'], true),
        ));
    }

    /**
     * Register the application services.
     */
    #[\Override]
    public function register(): void
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'payroll');

        // Register the main class to use with the facade
        $this->app->singleton(Payroll::class, fn (): Payroll => new Payroll);
        $this->app->alias(Payroll::class, 'laravel-payroll');
    }

    private function registerLivewireComponents(): void
    {
        if (!class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('payroll-dashboard', Http\Livewire\PayrollDashboard::class);
        Livewire::component('payroll-charts-card', Http\Livewire\PayrollChartsCard::class);
        Livewire::component('payroll-outstanding-card', Http\Livewire\PayrollOutstandingCard::class);
        Livewire::component('payroll-entries', Http\Livewire\PayrollEntriesPage::class);
        Livewire::component('payroll-employee-loans', Http\Livewire\EmployeeLoansPage::class);
        Livewire::component('payroll-employee-salary-ledger', Http\Livewire\EmployeeSalaryLedgerPage::class);
        Livewire::component('payroll-employee-salary-structure', Http\Livewire\EmployeeSalaryStructurePage::class);
        Livewire::component('payroll-entity-index', Http\Livewire\Entities\EntityIndexPage::class);
        Livewire::component('payroll-entity-form', Http\Livewire\Entities\EntityFormPage::class);
    }
}
