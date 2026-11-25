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
            if (! Schema::connection($connection)->hasTable('currencies')) {
                Schema::connection($connection)->create('currencies', function (Blueprint $table) {
                    $table->id();
                    $table->string('code', 10)->unique();
                    $table->string('name');
                    $table->string('symbol', 10)->nullable();
                    $table->unsignedTinyInteger('decimal_places')->default(3);
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                });
            }

            if (! Schema::connection($connection)->hasTable('main_vaults')) {
                Schema::connection($connection)->create('main_vaults', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->boolean('is_active')->default(true);
                    $table->text('notes')->nullable();
                    $table->timestamps();
                });
            }

            if (! Schema::connection($connection)->hasTable('main_vault_balances')) {
                Schema::connection($connection)->create('main_vault_balances', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('main_vault_id')->constrained('main_vaults')->cascadeOnDelete();
                    $table->foreignId('currency_id')->constrained('currencies');
                    $table->decimal('opening_balance', 18, 3)->default(0);
                    $table->decimal('current_balance', 18, 3)->default(0);
                    $table->timestamps();

                    $table->unique(['main_vault_id', 'currency_id']);
                });
            }

            if (! Schema::connection($connection)->hasTable('cashier_vaults')) {
                Schema::connection($connection)->create('cashier_vaults', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('user_id')->unique();
                    $table->string('name');
                    $table->boolean('is_active')->default(true);
                    $table->text('notes')->nullable();
                    $table->timestamps();
                });
            }
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            Schema::connection($connection)->dropIfExists('cashier_vaults');
            Schema::connection($connection)->dropIfExists('main_vault_balances');
            Schema::connection($connection)->dropIfExists('main_vaults');
            Schema::connection($connection)->dropIfExists('currencies');
        });
    }
};
