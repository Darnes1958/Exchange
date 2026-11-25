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
            if (! Schema::connection($connection)->hasTable('work_days')) {
                Schema::connection($connection)->create('work_days', function (Blueprint $table) {
                    $table->id();
                    $table->date('work_date');
                    $table->string('status', 16);
                    $table->dateTime('opened_at');
                    $table->dateTime('closed_at')->nullable();
                    $table->unsignedBigInteger('opened_by_user_id')->nullable();
                    $table->string('opened_by_name')->nullable();
                    $table->unsignedBigInteger('closed_by_user_id')->nullable();
                    $table->string('closed_by_name')->nullable();
                    $table->timestamps();
                    $table->index('status');
                });
            }

            if (! Schema::connection($connection)->hasTable('cashier_vault_balances')) {
                Schema::connection($connection)->create('cashier_vault_balances', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('cashier_vault_id')->constrained('cashier_vaults')->cascadeOnDelete();
                    $table->foreignId('currency_id')->constrained('currencies');
                    $table->decimal('current_balance', 18, 3)->default(0);
                    $table->timestamps();
                    $table->unique(['cashier_vault_id', 'currency_id']);
                });
            }

            if (! Schema::connection($connection)->hasTable('custody_handovers')) {
                Schema::connection($connection)->create('custody_handovers', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('work_day_id')->constrained('work_days');
                    $table->string('type', 16);
                    $table->foreignId('cashier_vault_id')->constrained('cashier_vaults');
                    $table->foreignId('main_vault_id')->constrained('main_vaults');
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->string('user_name')->nullable();
                    $table->text('notes')->nullable();
                    $table->timestamps();
                });
            }

            if (! Schema::connection($connection)->hasTable('custody_handover_lines')) {
                Schema::connection($connection)->create('custody_handover_lines', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('custody_handover_id')->constrained('custody_handovers')->cascadeOnDelete();
                    $table->foreignId('currency_id')->constrained('currencies');
                    $table->decimal('amount', 18, 3);
                    $table->timestamps();
                    $table->unique(['custody_handover_id', 'currency_id']);
                });
            }
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            Schema::connection($connection)->dropIfExists('custody_handover_lines');
            Schema::connection($connection)->dropIfExists('custody_handovers');
            Schema::connection($connection)->dropIfExists('cashier_vault_balances');
            Schema::connection($connection)->dropIfExists('work_days');
        });
    }
};
