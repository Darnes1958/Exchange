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
            if (Schema::connection($connection)->hasTable('exchange_deals')
                && ! Schema::connection($connection)->hasColumn('exchange_deals', 'status')) {
                Schema::connection($connection)->table('exchange_deals', function (Blueprint $table) {
                    $table->string('status', 16)->default('posted');
                });
            }

            if (Schema::connection($connection)->hasTable('exchange_deal_logs')) {
                return;
            }

            Schema::connection($connection)->create('exchange_deal_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exchange_deal_id')->constrained('exchange_deals');
                $table->string('action', 16);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->text('details');
                $table->json('before');
                $table->json('after')->nullable();
                $table->timestamps();
            });
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            Schema::connection($connection)->dropIfExists('exchange_deal_logs');

            if (Schema::connection($connection)->hasColumn('exchange_deals', 'status')) {
                Schema::connection($connection)->table('exchange_deals', function (Blueprint $table) {
                    $table->dropColumn('status');
                });
            }
        });
    }
};
