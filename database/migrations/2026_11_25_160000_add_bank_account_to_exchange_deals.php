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
            if (! Schema::connection($connection)->hasTable('exchange_deals')) {
                return;
            }

            if (Schema::connection($connection)->hasColumn('exchange_deals', 'bank_account_id')) {
                return;
            }

            Schema::connection($connection)->table('exchange_deals', function (Blueprint $table) {
                $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts');
            });
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            if (! Schema::connection($connection)->hasColumn('exchange_deals', 'bank_account_id')) {
                return;
            }

            Schema::connection($connection)->table('exchange_deals', function (Blueprint $table) {
                $table->dropConstrainedForeignId('bank_account_id');
            });
        });
    }
};
