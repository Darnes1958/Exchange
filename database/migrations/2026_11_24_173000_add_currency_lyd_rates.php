<?php

use App\Database\Migrations\Concerns\MigratesCompanyDatabases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use MigratesCompanyDatabases;

    /**
     * @var list<string>
     */
    private array $rates = [
        'buy_cash_rate',
        'buy_check_rate',
        'sell_cash_rate',
        'sell_check_rate',
    ];

    public function up(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            if (Schema::connection($connection)->hasTable('currencies')) {
                Schema::connection($connection)->table('currencies', function (Blueprint $table) use ($connection): void {
                    foreach ($this->rates as $rate) {
                        if (! Schema::connection($connection)->hasColumn('currencies', $rate)) {
                            $table->decimal($rate, 18, 6)->nullable();
                        }
                    }
                });

                $libyanDinar = [];

                foreach ($this->rates as $rate) {
                    $libyanDinar[$rate] = 1;
                }

                DB::connection($connection)
                    ->table('currencies')
                    ->where('code', 'LYD')
                    ->update($libyanDinar);
            }

            if (! Schema::connection($connection)->hasTable('currency_rate_changes')) {
                Schema::connection($connection)->create('currency_rate_changes', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->string('user_name')->nullable();

                    foreach ($this->rates as $rate) {
                        $table->decimal($rate.'_before', 18, 6)->nullable();
                        $table->decimal($rate.'_after', 18, 6)->nullable();
                    }

                    $table->timestamps();
                    $table->index(['currency_id', 'id']);
                });
            }
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            Schema::connection($connection)->dropIfExists('currency_rate_changes');

            if (! Schema::connection($connection)->hasTable('currencies')) {
                return;
            }

            Schema::connection($connection)->table('currencies', function (Blueprint $table) use ($connection): void {
                $columns = array_values(array_filter(
                    $this->rates,
                    fn (string $rate): bool => Schema::connection($connection)->hasColumn('currencies', $rate),
                ));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        });
    }
};
