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
            if (Schema::connection($connection)->hasTable('exchange_deals')) {
                return;
            }

            Schema::connection($connection)->create('exchange_deals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_day_id')->constrained('work_days');
                $table->string('type', 16);
                $table->string('payment', 16)->default('cash');
                $table->foreignId('cashier_vault_id')->constrained('cashier_vaults');
                $table->foreignId('customer_id')->constrained('customers');
                $table->foreignId('currency_id')->constrained('currencies');
                $table->decimal('quantity', 18, 3);
                $table->decimal('rate', 18, 6);
                $table->decimal('lyd_amount', 18, 3);
                $table->decimal('cost_price', 18, 6)->nullable();
                $table->decimal('profit', 18, 3)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index('work_day_id');
            });
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            Schema::connection($connection)->dropIfExists('exchange_deals');
        });
    }
};
