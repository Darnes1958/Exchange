<?php

use App\Database\Migrations\Concerns\MigratesCompanyDatabases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use MigratesCompanyDatabases;

    public function up(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            if (! Schema::connection($connection)->hasTable('main_vault_balances')) {
                return;
            }

            if (! Schema::connection($connection)->hasColumn('main_vault_balances', 'cost_price')) {
                Schema::connection($connection)->table('main_vault_balances', function (Blueprint $table): void {
                    $table->decimal('cost_price', 18, 6)->nullable();
                });
            }

            if (! Schema::connection($connection)->hasTable('currencies')) {
                return;
            }

            $libyanDinarIds = DB::connection($connection)
                ->table('currencies')
                ->where('code', 'LYD')
                ->pluck('id');

            if ($libyanDinarIds->isEmpty()) {
                return;
            }

            DB::connection($connection)
                ->table('main_vault_balances')
                ->whereIn('currency_id', $libyanDinarIds)
                ->whereNull('cost_price')
                ->update(['cost_price' => 1]);
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            if (! Schema::connection($connection)->hasColumn('main_vault_balances', 'cost_price')) {
                return;
            }

            Schema::connection($connection)->table('main_vault_balances', function (Blueprint $table): void {
                $table->dropColumn('cost_price');
            });
        });
    }
};
