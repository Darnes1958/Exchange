<?php

namespace Tests\Feature;

use App\Enums\BranchMode;
use App\Enums\UserRole;
use App\Enums\WorkDayStatus;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\CustodyHandovers\CustodyHandoverResource;
use App\Models\Branch;
use App\Models\CashierVault;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\MainVault;
use App\Models\MainVaultBalance;
use App\Models\User;
use App\Models\WorkDay;
use App\Services\DailyCustodyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExchangeBranchRulesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private MainVault $vault;

    private Currency $lyd;

    private Currency $usd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCompanyTables();

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
    }

    public function test_single_branch_hides_branch_choice_and_keeps_staff_rules(): void
    {
        $this->assertFalse(BranchResource::shouldRegisterNavigation());
        $this->assertFalse(BranchResource::canAccess());
        $this->assertSame('الفرع الرئيسي', $this->branch->name);

        $programmer = User::factory()->create([
            'role' => UserRole::Prog,
            'company' => 'sqlite',
            'branch_id' => $this->branch->id,
        ]);
        $generalManager = User::factory()->create([
            'role' => UserRole::SupperAdmin,
            'company' => 'sqlite',
            'branch_id' => $this->branch->id,
        ]);
        $manager = User::factory()->create([
            'role' => UserRole::Admin,
            'company' => 'sqlite',
        ]);
        $cashier = User::factory()->create([
            'role' => UserRole::Cashier,
            'company' => 'sqlite',
        ]);

        $this->assertNull($programmer->branch_id);
        $this->assertNull($generalManager->branch_id);
        $this->assertSame($this->branch->id, $manager->branch_id);
        $this->assertSame($this->branch->id, $cashier->branch_id);

        $programmer->branch_id = $this->branch->id;
        $programmer->save();
        $this->assertNull($programmer->branch_id);

        try {
            $manager->role = UserRole::Cashier;
            $manager->save();
            $this->fail('توقع رفض ترك الفرع بلا مدير.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }

        $this->assertSame(UserRole::Admin, $manager->fresh()->role);

        $secondManager = User::factory()->create([
            'role' => UserRole::Admin,
            'company' => 'sqlite',
        ]);

        $manager->role = UserRole::Cashier;
        $manager->save();
        $this->assertSame(UserRole::Cashier, $manager->fresh()->role);

        try {
            $secondManager->delete();
            $this->fail('توقع رفض حذف آخر مدير.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }

        $this->assertModelExists($secondManager);
        $manager->delete();
        $this->assertModelMissing($manager);
    }

    public function test_cashier_vault_follows_the_cashier_and_branch_has_one_main_vault(): void
    {
        $manager = $this->manager();
        $cashier = $this->cashier();

        try {
            CashierVault::query()->create([
                'user_id' => $manager->id,
                'name' => 'خزينة مدير',
                'is_active' => true,
            ]);
            $this->fail('توقع رفض ربط الخزينة بغير صراف.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user_id', $exception->errors());
        }

        $cashierVault = CashierVault::query()->create([
            'user_id' => $cashier->id,
            'name' => 'خزينة الصراف',
            'is_active' => true,
        ]);

        $this->assertSame($this->branch->id, $cashierVault->branch_id);

        try {
            MainVault::query()->create([
                'branch_id' => $this->branch->id,
                'name' => 'خزينة ثانية',
                'is_active' => true,
            ]);
            $this->fail('توقع رفض خزينة رئيسية ثانية للفرع.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_id', $exception->errors());
        }

        CompanySetting::query()->first()->update(['branch_mode' => BranchMode::Multiple]);
        $otherBranch = Branch::query()->create([
            'name' => 'فرع بنغازي',
            'is_active' => true,
        ]);

        $cashier->branch_id = $otherBranch->id;
        $cashier->save();

        $this->assertSame($otherBranch->id, $cashierVault->fresh()->branch_id);
        $this->assertTrue(BranchResource::shouldRegisterNavigation());
    }

    public function test_main_vault_balance_is_not_edited_manually_and_dinar_cost_stays_one(): void
    {
        $programmer = User::factory()->create([
            'role' => UserRole::Prog,
            'company' => 'sqlite',
        ]);
        $manager = $this->manager();

        $this->actingAs($programmer);

        $dinar = MainVaultBalance::query()->create([
            'main_vault_id' => $this->vault->id,
            'currency_id' => $this->lyd->id,
            'opening_balance' => 25,
            'cost_price' => 7,
        ]);
        $dollar = MainVaultBalance::query()->create([
            'main_vault_id' => $this->vault->id,
            'currency_id' => $this->usd->id,
            'opening_balance' => 10,
            'cost_price' => 5,
        ]);

        $this->assertEquals(1.0, (float) $dinar->fresh()->cost_price);
        $this->assertEquals(25.0, (float) $dinar->fresh()->current_balance);
        $this->assertEquals(5.0, (float) $dollar->fresh()->cost_price);
        $this->assertEquals(10.0, (float) $dollar->fresh()->current_balance);

        $dinar->opening_balance = 40;
        $dinar->cost_price = 3;
        $dinar->current_balance = 1;
        $dinar->save();

        $dinar->refresh();
        $this->assertEquals(40.0, (float) $dinar->opening_balance);
        $this->assertEquals(40.0, (float) $dinar->current_balance);
        $this->assertEquals(1.0, (float) $dinar->cost_price);

        $dollar->cost_price = 9;
        $dollar->current_balance = 99;
        $dollar->save();

        $dollar->refresh();
        $this->assertEquals(5.0, (float) $dollar->cost_price);
        $this->assertEquals(10.0, (float) $dollar->current_balance);

        $dollar->opening_balance = 12;
        $dollar->cost_price = 6;
        $dollar->save();

        $dollar->refresh();
        $this->assertEquals(12.0, (float) $dollar->opening_balance);
        $this->assertEquals(12.0, (float) $dollar->current_balance);
        $this->assertEquals(6.0, (float) $dollar->cost_price);

        $this->actingAs($manager);

        $dollar->opening_balance = 30;
        $dollar->cost_price = 8;
        $dollar->current_balance = 1;
        $dollar->save();

        $dollar->refresh();
        $this->assertEquals(12.0, (float) $dollar->opening_balance);
        $this->assertEquals(6.0, (float) $dollar->cost_price);
        $this->assertEquals(12.0, (float) $dollar->current_balance);

        $this->actingAs($programmer);

        $euro = Currency::query()->create([
            'code' => 'EUR',
            'name' => 'يورو',
            'is_active' => true,
        ]);

        $this->actingAs($manager);

        $foreign = MainVaultBalance::query()->create([
            'main_vault_id' => $this->vault->id,
            'currency_id' => $euro->id,
            'opening_balance' => 4,
            'cost_price' => 2,
        ]);

        $foreign->refresh();
        $this->assertEquals(0.0, (float) $foreign->opening_balance);
        $this->assertEquals(0.0, (float) $foreign->current_balance);
        $this->assertNull($foreign->cost_price);
    }

    public function test_custody_moves_only_after_the_day_opens_and_blocks_closing_until_returned(): void
    {
        $manager = $this->manager();
        $cashier = $this->cashier();
        $programmer = User::factory()->create([
            'role' => UserRole::Prog,
            'company' => 'sqlite',
        ]);

        $this->actingAs($programmer);

        $balance = MainVaultBalance::query()->create([
            'main_vault_id' => $this->vault->id,
            'currency_id' => $this->usd->id,
            'opening_balance' => 100,
            'cost_price' => 5,
        ]);

        $cashierVault = CashierVault::query()->create([
            'user_id' => $cashier->id,
            'branch_id' => $this->branch->id,
            'name' => 'خزينة الصراف',
            'is_active' => true,
        ]);

        $this->actingAs($manager);
        $this->assertFalse(CustodyHandoverResource::canCreate());

        try {
            $this->handOver($cashierVault->id, 'issue', 10);
            $this->fail('توقع رفض العهد قبل افتتاح اليوم.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('type', $exception->errors());
        }

        DailyCustodyService::open($manager);
        $this->assertTrue(CustodyHandoverResource::canCreate());

        try {
            DailyCustodyService::open($manager);
            $this->fail('توقع رفض افتتاح يوم ثانٍ.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_date', $exception->errors());
        }

        $this->handOver($cashierVault->id, 'issue', 40);
        $this->assertEquals(60.0, (float) $balance->fresh()->current_balance);
        $this->assertEquals(100.0, (float) $balance->fresh()->opening_balance);
        $this->assertEquals(40.0, (float) $cashierVault->balances()->first()->current_balance);

        try {
            $this->handOver($cashierVault->id, 'issue', 70);
            $this->fail('توقع رفض تجاوز رصيد الخزينة.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertEquals(60.0, (float) $balance->fresh()->current_balance);

        try {
            DailyCustodyService::close($manager);
            $this->fail('توقع رفض الإقفال قبل إرجاع العهد.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->handOver($cashierVault->id, 'return', 15);

        try {
            DailyCustodyService::close($manager);
            $this->fail('توقع رفض الإقفال مع بقاء جزء من العهد.');
        } catch (ValidationException) {
            $this->assertSame(WorkDayStatus::Open, WorkDay::current()?->status);
        }

        $this->handOver($cashierVault->id, 'return', 25);
        $closed = DailyCustodyService::close($manager);

        $this->assertSame(WorkDayStatus::Closed, $closed->status);
        $this->assertEquals(100.0, (float) $balance->fresh()->current_balance);
        $this->assertEquals(0.0, (float) $cashierVault->balances()->first()->current_balance);
        $this->assertFalse(CustodyHandoverResource::canCreate());
    }

    private function manager(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'company' => 'sqlite',
        ]);
    }

    private function cashier(): User
    {
        return User::factory()->create([
            'role' => UserRole::Cashier,
            'company' => 'sqlite',
        ]);
    }

    private function handOver(int $cashierVaultId, string $type, float $amount): void
    {
        $user = auth()->user();

        DailyCustodyService::handOver(
            [
                'type' => $type,
                'cashier_vault_id' => $cashierVaultId,
            ],
            [
                ['currency_id' => $this->usd->id, 'amount' => $amount],
            ],
            $user instanceof User ? $user : null,
        );
    }

    private function createCompanyTables(): void
    {
        if (Schema::hasTable('branches')) {
            return;
        }

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

        Schema::create('custody_handovers', function (Blueprint $table): void {
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

        Schema::create('custody_handover_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custody_handover_id')->constrained('custody_handovers')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('amount', 18, 3);
            $table->timestamps();
            $table->unique(['custody_handover_id', 'currency_id']);
        });
    }
}
