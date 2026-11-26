<?php

use App\Database\Migrations\Concerns\MigratesCompanyDatabases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use MigratesCompanyDatabases;

    public function up(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            if (! Schema::connection($connection)->hasTable('external_bank_movements')) {
                return;
            }

            Schema::connection($connection)->table('external_bank_movements', function (Blueprint $table) use ($connection): void {
                if (! Schema::connection($connection)->hasColumn('external_bank_movements', 'settlement')) {
                    $table->string('settlement', 16)->nullable();
                }

                if (! Schema::connection($connection)->hasColumn('external_bank_movements', 'local_bank_account_id')) {
                    $table->foreignId('local_bank_account_id')->nullable()->constrained('bank_accounts');
                }

                if (! Schema::connection($connection)->hasColumn('external_bank_movements', 'main_vault_id')) {
                    $table->foreignId('main_vault_id')->nullable()->constrained('main_vaults');
                }
            });
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            if (! Schema::connection($connection)->hasTable('external_bank_movements')) {
                return;
            }

            Schema::connection($connection)->table('external_bank_movements', function (Blueprint $table) use ($connection): void {
                if (Schema::connection($connection)->hasColumn('external_bank_movements', 'main_vault_id')) {
                    $table->dropConstrainedForeignId('main_vault_id');
                }

                if (Schema::connection($connection)->hasColumn('external_bank_movements', 'local_bank_account_id')) {
                    $table->dropConstrainedForeignId('local_bank_account_id');
                }

                if (Schema::connection($connection)->hasColumn('external_bank_movements', 'settlement')) {
                    $table->dropColumn('settlement');
                }
            });
        });
    }
};
