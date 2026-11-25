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

            if (Schema::connection($connection)->hasColumn('main_vault_balances', 'current_balance')) {
                return;
            }

            Schema::connection($connection)->table('main_vault_balances', function (Blueprint $table): void {
                $table->decimal('current_balance', 18, 3)->default(0);
            });

            DB::connection($connection)->table('main_vault_balances')->update([
                'current_balance' => DB::raw('opening_balance'),
            ]);
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            if (! Schema::connection($connection)->hasColumn('main_vault_balances', 'current_balance')) {
                return;
            }

            Schema::connection($connection)->table('main_vault_balances', function (Blueprint $table): void {
                $table->dropColumn('current_balance');
            });
        });
    }
};
