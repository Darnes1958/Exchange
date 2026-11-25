<?php

use App\Database\Migrations\Concerns\MigratesCompanyDatabases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use MigratesCompanyDatabases;

    public function up(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            if (! Schema::connection($connection)->hasTable('customers')) {
                Schema::connection($connection)->create('customers', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->string('phone', 30)->nullable();
                    $table->text('notes')->nullable();
                    $table->boolean('is_general')->default(false);
                    $table->timestamps();
                });
            }

            $hasGeneralCustomer = DB::connection($connection)
                ->table('customers')
                ->where('is_general', true)
                ->exists();

            if (! $hasGeneralCustomer && DB::connection($connection)->table('customers')->doesntExist()) {
                DB::connection($connection)->table('customers')->insert([
                    'name' => 'عام',
                    'phone' => null,
                    'notes' => null,
                    'is_general' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (! Schema::connection($connection)->hasTable('vouchers')) {
                Schema::connection($connection)->create('vouchers', function (Blueprint $table) {
                    $table->id();
                    $table->string('type', 16);
                    $table->foreignId('customer_id')->constrained('customers');
                    $table->foreignId('currency_id')->constrained('currencies');
                    $table->decimal('amount', 18, 3);
                    $table->date('voucher_date');
                    $table->text('notes')->nullable();
                    $table->timestamps();
                });
            }
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            Schema::connection($connection)->dropIfExists('vouchers');
            Schema::connection($connection)->dropIfExists('customers');
        });
    }
};
