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
            if (! Schema::connection($connection)->hasTable('external_sources')) {
                Schema::connection($connection)->create('external_sources', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }

            if (! Schema::connection($connection)->hasTable('external_beneficiaries')) {
                Schema::connection($connection)->create('external_beneficiaries', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }

            if (Schema::connection($connection)->hasTable('external_bank_movements')) {
                return;
            }

            Schema::connection($connection)->create('external_bank_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bank_account_id')->constrained('bank_accounts');
                $table->string('direction', 16);
                $table->string('purpose', 16);
                $table->string('reference', 255);
                $table->date('movement_date');
                $table->foreignId('currency_id')->constrained('currencies');
                $table->decimal('amount', 18, 3);
                $table->foreignId('external_source_id')->nullable()->constrained('external_sources');
                $table->foreignId('external_beneficiary_id')->nullable()->constrained('external_beneficiaries');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->timestamps();
            });
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            Schema::connection($connection)->dropIfExists('external_bank_movements');
            Schema::connection($connection)->dropIfExists('external_beneficiaries');
            Schema::connection($connection)->dropIfExists('external_sources');
        });
    }
};
