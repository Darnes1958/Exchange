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
                if (Schema::connection($connection)->hasColumn('external_bank_movements', 'counter_amount')) {
                    $table->dropColumn('counter_amount');
                }

                if (Schema::connection($connection)->hasColumn('external_bank_movements', 'rate')) {
                    $table->dropColumn('rate');
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
                if (! Schema::connection($connection)->hasColumn('external_bank_movements', 'rate')) {
                    $table->decimal('rate', 18, 6)->default(0);
                }

                if (! Schema::connection($connection)->hasColumn('external_bank_movements', 'counter_amount')) {
                    $table->decimal('counter_amount', 18, 3)->default(0);
                }
            });
        });
    }
};
