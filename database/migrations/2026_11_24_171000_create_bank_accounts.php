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
            if (! Schema::connection($connection)->hasTable('bank_accounts')) {
                Schema::connection($connection)->create('bank_accounts', function (Blueprint $table) {
                    $table->id();
                    $table->string('bank_name');
                    $table->string('account_number');
                    $table->string('type', 16);
                    $table->boolean('is_active')->default(true);
                    $table->text('notes')->nullable();
                    $table->timestamps();

                    $table->unique('account_number');
                });
            }

            if (! Schema::connection($connection)->hasTable('bank_account_balances')) {
                Schema::connection($connection)->create('bank_account_balances', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
                    $table->foreignId('currency_id')->constrained('currencies');
                    $table->decimal('opening_balance', 18, 3)->default(0);
                    $table->decimal('current_balance', 18, 3)->default(0);
                    $table->timestamps();

                    $table->unique(['bank_account_id', 'currency_id']);
                });
            }
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            Schema::connection($connection)->dropIfExists('bank_account_balances');
            Schema::connection($connection)->dropIfExists('bank_accounts');
        });
    }
};
