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
            if (! Schema::connection($connection)->hasTable('transfer_offices')) {
                Schema::connection($connection)->create('transfer_offices', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->decimal('balance', 18, 3)->default(0);
                    $table->timestamps();
                });
            }

            if (Schema::connection($connection)->hasTable('internal_transfers')) {
                return;
            }

            Schema::connection($connection)->create('internal_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_day_id')->constrained('work_days');
                $table->foreignId('transfer_office_id')->constrained('transfer_offices');
                $table->foreignId('cashier_vault_id')->constrained('cashier_vaults');
                $table->string('direction', 16);
                $table->string('status', 16);
                $table->string('reference', 255);
                $table->string('sender_name');
                $table->string('sender_phone', 30);
                $table->string('sender_identity', 255)->nullable();
                $table->decimal('amount', 18, 3);
                $table->decimal('commission', 18, 3);
                $table->string('commission_type', 16);
                $table->decimal('cash_amount', 18, 3);
                $table->decimal('office_amount', 18, 3);
                $table->decimal('profit', 18, 3);
                $table->string('settlement', 16)->nullable();
                $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts');
                $table->string('recipient_name')->nullable();
                $table->string('recipient_identity', 255)->nullable();
                $table->string('recipient_phone', 30)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->timestamps();
            });
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            Schema::connection($connection)->dropIfExists('internal_transfers');
            Schema::connection($connection)->dropIfExists('transfer_offices');
        });
    }
};
