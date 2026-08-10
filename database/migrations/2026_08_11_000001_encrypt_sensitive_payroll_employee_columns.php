<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{Crypt, DB, Schema};

return new class extends Migration
{
    private const ENCRYPTED_COLUMNS = ['monthly_salary', 'bank_account_name', 'bank_account_number', 'tax_id'];

    public function up(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection') ?? config('database.default');
        $schema = Schema::connection($connection);
        $table = $prefix . 'employees';
        $driver = $schema->getConnection()->getDriverName();

        // Widen these columns — encrypted ciphertext is far longer than the plaintext it
        // replaces. SQLite's column type affinity is dynamic and already tolerates the
        // longer strings, so only mysql/pgsql need an explicit ALTER.
        if ($driver === 'mysql') {
            foreach (self::ENCRYPTED_COLUMNS as $column) {
                DB::connection($connection)->statement("ALTER TABLE `{$table}` MODIFY `{$column}` TEXT NULL");
            }
        } elseif ($driver === 'pgsql') {
            foreach (self::ENCRYPTED_COLUMNS as $column) {
                DB::connection($connection)->statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE TEXT");
            }
        }

        DB::connection($connection)->table($table)->orderBy('id')->chunkById(200, function ($rows) use ($connection, $table): void {
            foreach ($rows as $row) {
                $update = [];

                foreach (self::ENCRYPTED_COLUMNS as $column) {
                    if ($row->{$column} !== null && $row->{$column} !== '') {
                        $update[$column] = Crypt::encryptString((string) $row->{$column});
                    }
                }

                if ($update !== []) {
                    DB::connection($connection)->table($table)->where('id', $row->id)->update($update);
                }
            }
        });
    }

    public function down(): void
    {
        $prefix = config('payroll.table_prefix', 'pay_');
        $connection = config('payroll.drivers.database.connection') ?? config('database.default');
        $table = $prefix . 'employees';

        DB::connection($connection)->table($table)->orderBy('id')->chunkById(200, function ($rows) use ($connection, $table): void {
            foreach ($rows as $row) {
                $update = [];

                foreach (self::ENCRYPTED_COLUMNS as $column) {
                    if ($row->{$column} !== null && $row->{$column} !== '') {
                        try {
                            $update[$column] = Crypt::decryptString($row->{$column});
                        } catch (\Throwable) {
                            // Already plaintext (e.g. row inserted after a partial rollback) — leave as-is.
                        }
                    }
                }

                if ($update !== []) {
                    DB::connection($connection)->table($table)->where('id', $row->id)->update($update);
                }
            }
        });
    }
};
