<?php

namespace Tests\Feature;

use App\Enums\BankAccountType;
use App\Enums\BranchMode;
use App\Enums\ExternalBankDirection;
use App\Enums\ExternalBankPurpose;
use App\Enums\ExternalSettlement;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\BankAccountBalance;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\ExternalBeneficiary;
use App\Models\ExternalSource;
use App\Models\MainVault;
use App\Models\MainVaultBalance;
use App\Models\User;
use App\Services\ExternalBankMovementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExternalBankMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_and_withdrawal_update_the_foreign_account_balance(): void
    {
        $this->createTables();

        $usd = Currency::query()->create([
            'code' => 'USD',
            'name' => 'دولار',
            'is_active' => true,
        ]);

        $account = BankAccount::query()->create([
            'bank_name' => 'مصرف خارجي',
            'account_number' => 'EXT-1',
            'type' => BankAccountType::Foreign,
            'is_active' => true,
        ]);

        $source = ExternalSource::query()->create(['name' => 'حوالة']);
        $beneficiary = ExternalBeneficiary::query()->create(['name' => 'مورد']);

        $deposit = ExternalBankMovementService::post([
            'direction' => ExternalBankDirection::Deposit->value,
            'purpose' => ExternalBankPurpose::Funds->value,
            'reference' => 'ايداع1',
            'movement_date' => '2026-11-26',
            'bank_account_id' => $account->id,
            'currency_id' => $usd->id,
            'amount' => 100,
            'rate' => 6.5,
            'external_source_id' => $source->id,
        ], null);

        $this->assertSame(ExternalBankPurpose::Funds, $deposit->purpose);
        $this->assertEqualsWithDelta(100, (float) $deposit->amount, 0.001);
        $this->assertEqualsWithDelta(6.5, (float) $deposit->rate, 0.000001);
        $this->assertEqualsWithDelta(650, (float) $deposit->counter_amount, 0.001);
        $this->assertEqualsWithDelta(100, $this->balance($account->id, $usd->id), 0.001);

        ExternalBankMovementService::post([
            'direction' => ExternalBankDirection::Withdrawal->value,
            'purpose' => ExternalBankPurpose::Funds->value,
            'reference' => 'سحب2',
            'bank_account_id' => $account->id,
            'currency_id' => $usd->id,
            'amount' => 40,
            'rate' => 6.4,
            'external_beneficiary_id' => $beneficiary->id,
        ], null);

        $this->assertEqualsWithDelta(60, $this->balance($account->id, $usd->id), 0.001);

        try {
            ExternalBankMovementService::post([
                'direction' => ExternalBankDirection::Withdrawal->value,
                'purpose' => ExternalBankPurpose::Funds->value,
                'reference' => 'سحب3',
                'bank_account_id' => $account->id,
                'currency_id' => $usd->id,
                'amount' => 100,
                'rate' => 6.4,
                'external_beneficiary_id' => $beneficiary->id,
            ], null);
            $this->fail('توقع رفض السحب فوق الرصيد.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertEqualsWithDelta(60, $this->balance($account->id, $usd->id), 0.001);
    }

    public function test_currency_purchase_for_cash_deducts_the_manager_vault(): void
    {
        $this->createTables();
        $context = $this->settlementContext();

        $this->actingAs($context['manager']);

        $deposit = ExternalBankMovementService::post([
            'direction' => ExternalBankDirection::Deposit->value,
            'purpose' => ExternalBankPurpose::Currency->value,
            'settlement' => ExternalSettlement::Cash->value,
            'reference' => 'شراء كاش',
            'bank_account_id' => $context['external']->id,
            'currency_id' => $context['usd']->id,
            'amount' => 100,
            'rate' => 6.5,
            'external_source_id' => $context['source']->id,
        ], $context['manager']);

        $this->assertSame(ExternalSettlement::Cash, $deposit->settlement);
        $this->assertSame($context['vault']->id, $deposit->main_vault_id);
        $this->assertNull($deposit->local_bank_account_id);
        $this->assertEqualsWithDelta(650, (float) $deposit->counter_amount, 0.001);
        $this->assertEqualsWithDelta(100, $this->balance($context['external']->id, $context['usd']->id), 0.001);
        $this->assertEqualsWithDelta(350, $this->vaultLyd($context['vault']->id, $context['lyd']->id), 0.001);
        $this->assertEqualsWithDelta(800, $this->balance($context['local']->id, $context['lyd']->id), 0.001);
    }

    public function test_currency_sale_for_a_local_bank_adds_lyd_there(): void
    {
        $this->createTables();
        $context = $this->settlementContext();

        ExternalBankMovementService::post([
            'direction' => ExternalBankDirection::Deposit->value,
            'purpose' => ExternalBankPurpose::Funds->value,
            'reference' => 'رصيد',
            'bank_account_id' => $context['external']->id,
            'currency_id' => $context['usd']->id,
            'amount' => 40,
            'rate' => 1,
            'external_source_id' => $context['source']->id,
        ], $context['manager']);

        $this->actingAs($context['manager']);

        $withdrawal = ExternalBankMovementService::post([
            'direction' => ExternalBankDirection::Withdrawal->value,
            'purpose' => ExternalBankPurpose::Currency->value,
            'settlement' => ExternalSettlement::Bank->value,
            'local_bank_account_id' => $context['local']->id,
            'reference' => 'بيع مصرفي',
            'bank_account_id' => $context['external']->id,
            'currency_id' => $context['usd']->id,
            'amount' => 40,
            'rate' => 6.4,
            'external_beneficiary_id' => $context['beneficiary']->id,
        ], $context['manager']);

        $this->assertSame(ExternalSettlement::Bank, $withdrawal->settlement);
        $this->assertSame($context['local']->id, $withdrawal->local_bank_account_id);
        $this->assertNull($withdrawal->main_vault_id);
        $this->assertEqualsWithDelta(0, $this->balance($context['external']->id, $context['usd']->id), 0.001);
        $this->assertEqualsWithDelta(1056, $this->balance($context['local']->id, $context['lyd']->id), 0.001);
        $this->assertEqualsWithDelta(1000, $this->vaultLyd($context['vault']->id, $context['lyd']->id), 0.001);
    }

    public function test_currency_purchase_rejects_a_local_bank_without_enough_lyd(): void
    {
        $this->createTables();
        $context = $this->settlementContext();

        BankAccountBalance::query()
            ->where('bank_account_id', $context['local']->id)
            ->where('currency_id', $context['lyd']->id)
            ->update(['current_balance' => 10]);

        $this->actingAs($context['manager']);

        try {
            ExternalBankMovementService::post([
                'direction' => ExternalBankDirection::Deposit->value,
                'purpose' => ExternalBankPurpose::Currency->value,
                'settlement' => ExternalSettlement::Bank->value,
                'local_bank_account_id' => $context['local']->id,
                'reference' => 'شراء بلا رصيد',
                'bank_account_id' => $context['external']->id,
                'currency_id' => $context['usd']->id,
                'amount' => 100,
                'rate' => 6.5,
                'external_source_id' => $context['source']->id,
            ], $context['manager']);
            $this->fail('توقع رفض الشراء فوق رصيد المصرف المحلي.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('local_bank_account_id', $exception->errors());
        }

        $this->assertNull(BankAccountBalance::query()
            ->where('bank_account_id', $context['external']->id)
            ->where('currency_id', $context['usd']->id)
            ->value('current_balance'));
        $this->assertEqualsWithDelta(10, $this->balance($context['local']->id, $context['lyd']->id), 0.001);
    }

    /**
     * @return array{manager: User, vault: MainVault, lyd: Currency, usd: Currency, external: BankAccount, local: BankAccount, source: ExternalSource, beneficiary: ExternalBeneficiary}
     */
    private function settlementContext(): array
    {
        $branch = Branch::query()->create([
            'name' => 'الفرع الرئيسي',
            'is_active' => true,
        ]);

        CompanySetting::query()->create([
            'branch_mode' => BranchMode::Single,
        ]);

        $lyd = Currency::query()->create([
            'code' => 'LYD',
            'name' => 'دينار ليبي',
            'is_active' => true,
        ]);

        $usd = Currency::query()->where('code', 'USD')->first();

        if (! $usd instanceof Currency) {
            $usd = Currency::query()->create([
                'code' => 'USD',
                'name' => 'دولار',
                'is_active' => true,
            ]);
        }

        $vault = MainVault::query()->create([
            'branch_id' => $branch->id,
            'name' => 'الخزينة الرئيسية',
            'is_active' => true,
        ]);

        MainVaultBalance::query()->create([
            'main_vault_id' => $vault->id,
            'currency_id' => $lyd->id,
            'opening_balance' => 1000,
        ]);

        $external = BankAccount::query()->create([
            'bank_name' => 'مصرف خارجي',
            'account_number' => 'EXT-SETTLE',
            'type' => BankAccountType::Foreign,
            'is_active' => true,
        ]);

        $local = BankAccount::query()->create([
            'bank_name' => 'مصرف محلي',
            'account_number' => 'LOC-1',
            'type' => BankAccountType::Local,
            'is_active' => true,
        ]);

        BankAccountBalance::query()->create([
            'bank_account_id' => $local->id,
            'currency_id' => $lyd->id,
            'opening_balance' => 800,
        ]);

        $manager = User::factory()->create([
            'role' => UserRole::Admin,
            'company' => 'sqlite',
        ]);

        return [
            'manager' => $manager,
            'vault' => $vault,
            'lyd' => $lyd,
            'usd' => $usd,
            'external' => $external,
            'local' => $local,
            'source' => ExternalSource::query()->create(['name' => 'مصدر']),
            'beneficiary' => ExternalBeneficiary::query()->create(['name' => 'مستفيد']),
        ];
    }

    private function vaultLyd(int $vaultId, int $lydId): float
    {
        return (float) MainVaultBalance::query()
            ->where('main_vault_id', $vaultId)
            ->where('currency_id', $lydId)
            ->value('current_balance');
    }

    private function balance(int $accountId, int $currencyId): float
    {
        return (float) BankAccountBalance::query()
            ->where('bank_account_id', $accountId)
            ->where('currency_id', $currencyId)
            ->value('current_balance');
    }

    private function createTables(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('symbol', 10)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(3);
            $table->boolean('is_active')->default(true);
            $table->decimal('buy_cash_rate', 18, 6)->nullable();
            $table->decimal('buy_check_rate', 18, 6)->nullable();
            $table->decimal('sell_cash_rate', 18, 6)->nullable();
            $table->decimal('sell_check_rate', 18, 6)->nullable();
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('bank_name');
            $table->string('account_number')->unique();
            $table->string('type', 16);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_account_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('opening_balance', 18, 3)->default(0);
            $table->decimal('current_balance', 18, 3)->default(0);
            $table->timestamps();
            $table->unique(['bank_account_id', 'currency_id']);
        });

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('branch_mode', 16)->default('single');
            $table->timestamps();
        });

        Schema::create('main_vaults', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained('branches');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('main_vault_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('main_vault_id')->constrained('main_vaults')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('opening_balance', 18, 3)->default(0);
            $table->decimal('current_balance', 18, 3)->default(0);
            $table->decimal('cost_price', 18, 6)->nullable();
            $table->timestamps();
            $table->unique(['main_vault_id', 'currency_id']);
        });

        Schema::create('external_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('external_beneficiaries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('external_bank_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts');
            $table->string('direction', 16);
            $table->string('purpose', 16);
            $table->string('reference', 64);
            $table->date('movement_date');
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('amount', 18, 3);
            $table->decimal('rate', 18, 6);
            $table->decimal('counter_amount', 18, 3);
            $table->string('settlement', 16)->nullable();
            $table->unsignedBigInteger('local_bank_account_id')->nullable();
            $table->unsignedBigInteger('main_vault_id')->nullable();
            $table->foreignId('external_source_id')->nullable()->constrained('external_sources');
            $table->foreignId('external_beneficiary_id')->nullable()->constrained('external_beneficiaries');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->timestamps();
        });
    }
}
