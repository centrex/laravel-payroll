<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection') ?? config('database.default');
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'employees', function (Blueprint $table) use ($prefix, $schema): void {
            // Links this payroll employee to the system user credited as sales rep on
            // Centrex\Inventory\Models\SaleOrder (sales_executive_id) — no FK constraint
            // since the users table may live on a different connection.
            if (!$schema->hasColumn($prefix . 'employees', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('code');
            }

            if (!$schema->hasColumn($prefix . 'employees', 'commission_rate')) {
                $table->decimal('commission_rate', 8, 4)->nullable()->after('user_id');
            }
        });
    }

    public function down(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection') ?? config('database.default');
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'employees', function (Blueprint $table) use ($prefix, $schema): void {
            $columns = array_filter([
                $schema->hasColumn($prefix . 'employees', 'user_id') ? 'user_id' : null,
                $schema->hasColumn($prefix . 'employees', 'commission_rate') ? 'commission_rate' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
