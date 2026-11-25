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
        if (! Schema::hasColumn('users', 'branch_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->nullable()->index();
            });
        }

        $this->onEachCompanyConnection(function (string $connection): void {
            if (! Schema::connection($connection)->hasTable('company_settings')) {
                Schema::connection($connection)->create('company_settings', function (Blueprint $table) {
                    $table->id();
                    $table->string('branch_mode', 16)->default('single');
                    $table->timestamps();
                });
            }

            if (! Schema::connection($connection)->hasTable('branches')) {
                Schema::connection($connection)->create('branches', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                });
            }

            $now = now();

            if (DB::connection($connection)->table('company_settings')->doesntExist()) {
                DB::connection($connection)->table('company_settings')->insert([
                    'branch_mode' => 'single',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $branchId = DB::connection($connection)->table('branches')->orderBy('id')->value('id');

            if ($branchId === null) {
                $branchId = DB::connection($connection)->table('branches')->insertGetId([
                    'name' => 'الفرع الرئيسي',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (! Schema::connection($connection)->hasColumn('main_vaults', 'branch_id')) {
                Schema::connection($connection)->table('main_vaults', function (Blueprint $table) {
                    $table->foreignId('branch_id')->nullable()->constrained('branches');
                });
            }

            if (! Schema::connection($connection)->hasColumn('cashier_vaults', 'branch_id')) {
                Schema::connection($connection)->table('cashier_vaults', function (Blueprint $table) {
                    $table->foreignId('branch_id')->nullable()->constrained('branches');
                });
            }

            DB::connection($connection)->table('main_vaults')->whereNull('branch_id')->update([
                'branch_id' => $branchId,
            ]);
            DB::connection($connection)->table('cashier_vaults')->whereNull('branch_id')->update([
                'branch_id' => $branchId,
            ]);

            if (! $this->hasUniqueBranchIndex($connection, 'main_vaults')) {
                Schema::connection($connection)->table('main_vaults', function (Blueprint $table) {
                    $table->unique('branch_id');
                });
            }

            DB::table('users')
                ->where('company', $connection)
                ->whereIn('role', ['admin', 'cashier'])
                ->whereNull('branch_id')
                ->update(['branch_id' => $branchId]);
        });
    }

    public function down(): void
    {
        $this->onEachCompanyConnection(function (string $connection): void {
            if (Schema::connection($connection)->hasColumn('cashier_vaults', 'branch_id')) {
                Schema::connection($connection)->table('cashier_vaults', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('branch_id');
                });
            }

            if (Schema::connection($connection)->hasColumn('main_vaults', 'branch_id')) {
                Schema::connection($connection)->table('main_vaults', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('branch_id');
                });
            }

            Schema::connection($connection)->dropIfExists('branches');
            Schema::connection($connection)->dropIfExists('company_settings');
        });

        if (Schema::hasColumn('users', 'branch_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['branch_id']);
                $table->dropColumn('branch_id');
            });
        }
    }

    private function hasUniqueBranchIndex(string $connection, string $table): bool
    {
        foreach (Schema::connection($connection)->getIndexes($table) as $index) {
            $columns = $index['columns'] ?? [];
            $unique = (bool) ($index['unique'] ?? false);

            if ($unique && $columns === ['branch_id']) {
                return true;
            }
        }

        return false;
    }
};
