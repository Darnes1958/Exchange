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

            Schema::connection($connection)->table('external_bank_movements', function (Blueprint $table): void {
                $table->string('reference', 255)->change();
            });
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            if (! Schema::connection($connection)->hasTable('external_bank_movements')) {
                return;
            }

            Schema::connection($connection)->table('external_bank_movements', function (Blueprint $table): void {
                $table->string('reference', 64)->change();
            });
        });
    }
};
