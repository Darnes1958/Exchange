<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Currency;
use App\Models\MainVault;
use App\Models\MainVaultBalance;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExchangeFoundationSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->runningUnitTests() || app()->environment('testing')) {
            return;
        }

        Company::query()->updateOrCreate(
            ['connection_name' => 'testEXC'],
            [
                'database_name' => 'testEXC',
                'name' => 'شركة المهاري للصرافة',
                'is_active' => true,
            ],
        );

        $linked = User::query()
            ->where('email', 'abdel_ati_m@yahoo.com')
            ->update([
                'role' => UserRole::Prog->value,
                'company' => 'testEXC',
            ]);

        if ($linked === 0 && User::query()->where('company', 'testEXC')->doesntExist()) {
            User::query()->orderBy('id')->limit(1)->update([
                'role' => UserRole::Prog->value,
                'company' => 'testEXC',
            ]);
        }

        $currencies = [
            ['code' => 'LYD', 'name' => 'دينار ليبي', 'symbol' => 'د.ل', 'decimal_places' => 3],
            ['code' => 'USD', 'name' => 'دولار أمريكي', 'symbol' => '$', 'decimal_places' => 2],
            ['code' => 'EUR', 'name' => 'يورو', 'symbol' => '€', 'decimal_places' => 2],
            ['code' => 'GBP', 'name' => 'جنيه إسترليني', 'symbol' => '£', 'decimal_places' => 2],
            ['code' => 'TND', 'name' => 'دينار تونسي', 'symbol' => 'د.ت', 'decimal_places' => 3],
            ['code' => 'EGP', 'name' => 'جنيه مصري', 'symbol' => 'ج.م', 'decimal_places' => 2],
            ['code' => 'TRY', 'name' => 'ليرة تركية', 'symbol' => '₺', 'decimal_places' => 2],
        ];

        foreach ($currencies as $currency) {
            Currency::on('testEXC')->updateOrCreate(
                ['code' => $currency['code']],
                [...$currency, 'is_active' => true],
            );
        }

        $vault = MainVault::on('testEXC')->updateOrCreate(
            ['name' => 'الخزينة الرئيسية'],
            [
                'is_active' => true,
                'notes' => 'الخزينة الرئيسية لشركة المهاري للصرافة',
            ],
        );

        foreach (['LYD', 'USD', 'EUR'] as $code) {
            $currency = Currency::on('testEXC')->where('code', $code)->first();

            if ($currency === null) {
                continue;
            }

            MainVaultBalance::on('testEXC')->updateOrCreate(
                [
                    'main_vault_id' => $vault->id,
                    'currency_id' => $currency->id,
                ],
                [
                    'opening_balance' => 0,
                    'current_balance' => 0,
                ],
            );
        }
    }
}
