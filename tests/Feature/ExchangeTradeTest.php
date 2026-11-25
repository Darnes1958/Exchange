<?php

namespace Tests\Feature;

use App\Enums\BranchMode;
use App\Enums\DealLogAction;
use App\Enums\DealStatus;
use App\Enums\TradeSide;
use App\Enums\UserRole;
use App\Enums\WorkDayStatus;
use App\Models\Branch;
use App\Models\CashierVault;
use App\Models\CashierVaultBalance;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Filament\Resources\ExchangeDeals\ExchangeDealResource;
use App\Models\ExchangeDeal;
use App\Models\ExchangeDealLog;
use App\Models\MainVault;
use App\Models\MainVaultBalance;
use App\Models\User;
use App\Models\WorkDay;
use App\Services\ExchangeTradeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExchangeTradeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private MainVault $vault;

    private Currency $lyd;

    private Currency $usd;

    private Customer $customer;

    private User $cashier;

    private CashierVault $cashierVault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $this->branch = Branch::query()->create([
            'name' => 'الفرع الرئيسي',
            'is_active' => true,
        ]);

        CompanySetting::query()->create([
            'branch_mode' => BranchMode::Single,
        ]);

        $this->lyd = Currency::query()->create([
            'code' => 'LYD',
            'name' => 'دينار ليبي',
            'symbol' => 'د.ل',
            'decimal_places' => 3,
            'is_active' => true,
        ]);

        $this->usd = Currency::query()->create([
            'code' => 'USD',
            'name' => 'دولار أمريكي',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_active' => true,
        ]);

        $this->vault = MainVault::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'الخزينة الرئيسية',
            'is_active' => true,
        ]);

        $this->customer = new Customer;
        $this->customer->forceFill([
            'name' => 'عام',
            'is_general' => true,
        ])->save();

        $this->cashier = User::factory()->create([
            'role' => UserRole::Cashier,
            'company' => 'sqlite',
        ]);

        $this->cashierVault = CashierVault::query()->create([
            'user_id' => $this->cashier->id,
            'name' => 'عهدة الصراف',
            'is_active' => true,
        ]);
    }

    public function test_sale_keeps_main_vault_quantity_and_stores_profit(): void
    {
        $this->putMainBalance(100, '5.000000');
        $this->putCashierBalance($this->cashierVault, $this->usd, '40.000');
        $this->putCashierBalance($this->cashierVault, $this->lyd, '10.000');
        $this->openDay();

        $this->actingAs($this->cashier);

        $deal = ExchangeTradeService::trade([
            'type' => TradeSide::Sell->value,
            'customer_id' => $this->customer->id,
            'currency_id' => $this->usd->id,
            'quantity' => 10,
            'rate' => '5.500000',
        ], $this->cashier);

        $this->assertSame(TradeSide::Sell, $deal->type);
        $this->assertEqualsWithDelta(55, (float) $deal->lyd_amount, 0.001);
        $this->assertEqualsWithDelta(5, (float) $deal->cost_price, 0.000001);
        $this->assertEqualsWithDelta(5, (float) $deal->profit, 0.001);
        $this->assertEqualsWithDelta(30, $this->cashierAmount($this->cashierVault, $this->usd), 0.001);
        $this->assertEqualsWithDelta(65, $this->cashierAmount($this->cashierVault, $this->lyd), 0.001);

        $main = $this->mainBalance();
        $this->assertEqualsWithDelta(100, (float) $main->current_balance, 0.001);
        $this->assertEqualsWithDelta(5, (float) $main->cost_price, 0.000001);
    }

    public function test_purchase_averages_main_vault_and_today_custody_without_adding_quantity(): void
    {
        $this->putMainBalance(100, '5.000000');
        $this->putCashierBalance($this->cashierVault, $this->usd, '20.000');
        $this->putCashierBalance($this->cashierVault, $this->lyd, '1000.000');

        $other = User::factory()->create([
            'role' => UserRole::Cashier,
            'company' => 'sqlite',
        ]);
        $otherVault = CashierVault::query()->create([
            'user_id' => $other->id,
            'name' => 'عهدة صراف آخر',
            'is_active' => true,
        ]);
        $this->putCashierBalance($otherVault, $this->usd, '30.000');
        $this->openDay();

        $this->actingAs($this->cashier);

        $deal = ExchangeTradeService::trade([
            'type' => TradeSide::Buy->value,
            'customer_id' => $this->customer->id,
            'currency_id' => $this->usd->id,
            'quantity' => 50,
            'rate' => 6,
        ], $this->cashier);

        $this->assertNull($deal->cost_price);
        $this->assertNull($deal->profit);
        $this->assertEqualsWithDelta(300, (float) $deal->lyd_amount, 0.001);
        $this->assertEqualsWithDelta(70, $this->cashierAmount($this->cashierVault, $this->usd), 0.001);
        $this->assertEqualsWithDelta(700, $this->cashierAmount($this->cashierVault, $this->lyd), 0.001);
        $this->assertEqualsWithDelta(30, $this->cashierAmount($otherVault, $this->usd), 0.001);

        $main = $this->mainBalance();
        $this->assertEqualsWithDelta(100, (float) $main->current_balance, 0.001);
        $this->assertEqualsWithDelta(5.25, (float) $main->cost_price, 0.000001);
    }

    public function test_trade_is_limited_to_the_cashier_custody(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'company' => 'sqlite',
        ]);

        try {
            ExchangeTradeService::trade([
                'type' => TradeSide::Sell->value,
                'customer_id' => $this->customer->id,
                'currency_id' => $this->usd->id,
                'quantity' => 1,
                'rate' => 5,
            ], $admin);
            $this->fail('توقع رفض غير الصراف.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('type', $exception->errors());
        }

        $this->putCashierBalance($this->cashierVault, $this->usd, '1.000');

        try {
            ExchangeTradeService::trade([
                'type' => TradeSide::Sell->value,
                'customer_id' => $this->customer->id,
                'currency_id' => $this->usd->id,
                'quantity' => 5,
                'rate' => 5,
            ], $this->cashier);
            $this->fail('توقع رفض البيع بلا عمل يومي.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('type', $exception->errors());
        }

        $this->openDay();
        $this->actingAs($this->cashier);

        try {
            ExchangeTradeService::trade([
                'type' => TradeSide::Sell->value,
                'customer_id' => $this->customer->id,
                'currency_id' => $this->usd->id,
                'quantity' => 5,
                'rate' => 5,
            ], $this->cashier);
            $this->fail('توقع رفض تجاوز رصيد العهدة.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertEqualsWithDelta(1, $this->cashierAmount($this->cashierVault, $this->usd), 0.001);
        $this->assertSame(0, ExchangeDeal::query()->count());
    }

    public function test_today_sale_can_be_edited_and_cancelled_with_a_full_log(): void
    {
        $this->putMainBalance(100, '5.000000');
        $this->putCashierBalance($this->cashierVault, $this->usd, '40.000');
        $this->putCashierBalance($this->cashierVault, $this->lyd, '10.000');
        $this->openDay();
        $this->actingAs($this->cashier);

        $deal = ExchangeTradeService::trade([
            'type' => TradeSide::Sell->value,
            'customer_id' => $this->customer->id,
            'currency_id' => $this->usd->id,
            'quantity' => 10,
            'rate' => '5.500000',
        ], $this->cashier);

        $this->assertSame(1, ExchangeDealResource::getEloquentQuery()->count());

        $deal = ExchangeTradeService::updateSale($deal, [
            'customer_id' => $this->customer->id,
            'currency_id' => $this->usd->id,
            'quantity' => 8,
            'rate' => 6,
            'payment' => 'cash',
        ], $this->cashier);

        $this->assertEqualsWithDelta(48, (float) $deal->lyd_amount, 0.001);
        $this->assertEqualsWithDelta(5, (float) $deal->cost_price, 0.000001);
        $this->assertEqualsWithDelta(8, (float) $deal->profit, 0.001);
        $this->assertEqualsWithDelta(32, $this->cashierAmount($this->cashierVault, $this->usd), 0.001);
        $this->assertEqualsWithDelta(58, $this->cashierAmount($this->cashierVault, $this->lyd), 0.001);

        $editLog = ExchangeDealLog::query()->first();
        $this->assertSame(DealLogAction::Edit, $editLog->action);
        $this->assertSame('10.000', $editLog->before['quantity']);
        $this->assertStringContainsString('الكمية', $editLog->details);
        $this->assertStringContainsString('8.000', $editLog->details);

        $deal = ExchangeTradeService::cancelSale($deal, $this->cashier);
        $this->assertSame(DealStatus::Cancelled, $deal->status);
        $this->assertEqualsWithDelta(40, $this->cashierAmount($this->cashierVault, $this->usd), 0.001);
        $this->assertEqualsWithDelta(10, $this->cashierAmount($this->cashierVault, $this->lyd), 0.001);
        $this->assertSame(DealLogAction::Cancel, ExchangeDealLog::query()->latest('id')->first()->action);
        $this->assertFalse(ExchangeDealResource::canRevise($deal));

        WorkDay::query()->update(['status' => WorkDayStatus::Closed]);
        $this->assertSame(0, ExchangeDealResource::getEloquentQuery()->count());
    }

    private function openDay(): void
    {
        WorkDay::query()->create([
            'work_date' => now()->toDateString(),
            'status' => WorkDayStatus::Open,
            'opened_at' => now(),
        ]);
    }

    private function putMainBalance(float $amount, string $cost): void
    {
        $balance = MainVaultBalance::query()->create([
            'main_vault_id' => $this->vault->id,
            'currency_id' => $this->usd->id,
            'opening_balance' => $amount,
            'cost_price' => $cost,
        ]);

        $this->assertEqualsWithDelta($amount, (float) $balance->current_balance, 0.001);
        $this->assertEqualsWithDelta((float) $cost, (float) $balance->cost_price, 0.000001);
    }

    private function putCashierBalance(CashierVault $vault, Currency $currency, string $amount): void
    {
        $balance = new CashierVaultBalance;
        $balance->cashier_vault_id = $vault->id;
        $balance->currency_id = $currency->id;
        $balance->current_balance = $amount;
        $balance->save();
    }

    private function cashierAmount(CashierVault $vault, Currency $currency): float
    {
        return (float) CashierVaultBalance::query()
            ->where('cashier_vault_id', $vault->id)
            ->where('currency_id', $currency->id)
            ->value('current_balance');
    }

    private function mainBalance(): MainVaultBalance
    {
        $balance = MainVaultBalance::query()
            ->where('main_vault_id', $this->vault->id)
            ->where('currency_id', $this->usd->id)
            ->first();

        $this->assertInstanceOf(MainVaultBalance::class, $balance);

        return $balance;
    }

    private function createTables(): void
    {
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

        Schema::create('currency_rate_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->decimal('buy_cash_rate_before', 18, 6)->nullable();
            $table->decimal('buy_cash_rate_after', 18, 6)->nullable();
            $table->decimal('buy_check_rate_before', 18, 6)->nullable();
            $table->decimal('buy_check_rate_after', 18, 6)->nullable();
            $table->decimal('sell_cash_rate_before', 18, 6)->nullable();
            $table->decimal('sell_cash_rate_after', 18, 6)->nullable();
            $table->decimal('sell_check_rate_before', 18, 6)->nullable();
            $table->decimal('sell_check_rate_after', 18, 6)->nullable();
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

        Schema::create('cashier_vaults', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cashier_vault_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cashier_vault_id')->constrained('cashier_vaults')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('current_balance', 18, 3)->default(0);
            $table->timestamps();
            $table->unique(['cashier_vault_id', 'currency_id']);
        });

        Schema::create('work_days', function (Blueprint $table): void {
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
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_general')->default(false);
            $table->timestamps();
        });

        Schema::create('exchange_deals', function (Blueprint $table): void {
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
            $table->string('status', 16)->default('posted');
            $table->timestamps();
        });

        Schema::create('exchange_deal_logs', function (Blueprint $table): void {
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
    }
}
