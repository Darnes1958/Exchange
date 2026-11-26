<?php

namespace Tests\Feature;

use App\Enums\BankAccountType;
use App\Enums\BranchMode;
use App\Enums\IncomingCommission;
use App\Enums\InternalTransferDirection;
use App\Enums\InternalTransferSettlement;
use App\Enums\InternalTransferStatus;
use App\Enums\OutgoingCommission;
use App\Enums\UserRole;
use App\Enums\WorkDayStatus;
use App\Models\BankAccount;
use App\Models\BankAccountBalance;
use App\Models\Branch;
use App\Models\CashierVault;
use App\Models\CashierVaultBalance;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\TransferOffice;
use App\Models\User;
use App\Models\WorkDay;
use App\Services\InternalTransferService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InternalTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private CashierVault $vault;

    private Currency $lyd;

    private TransferOffice $office;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $branch = Branch::query()->create([
            'name' => 'الفرع الرئيسي',
            'is_active' => true,
        ]);

        CompanySetting::query()->create([
            'branch_mode' => BranchMode::Single,
        ]);

        $this->lyd = Currency::query()->create([
            'code' => 'LYD',
            'name' => 'دينار ليبي',
            'is_active' => true,
        ]);

        $this->cashier = User::factory()->create([
            'role' => UserRole::Cashier,
            'company' => 'sqlite',
        ]);

        $this->vault = CashierVault::query()->create([
            'user_id' => $this->cashier->id,
            'branch_id' => $branch->id,
            'name' => 'عهدة الصراف',
            'is_active' => true,
        ]);

        $balance = new CashierVaultBalance;
        $balance->cashier_vault_id = $this->vault->id;
        $balance->currency_id = $this->lyd->id;
        $balance->current_balance = '1000.000';
        $balance->save();

        WorkDay::query()->create([
            'work_date' => now()->toDateString(),
            'status' => WorkDayStatus::Open,
            'opened_at' => now(),
        ]);

        $this->office = TransferOffice::query()->create([
            'name' => 'مكتب طرابلس',
            'balance' => 0,
        ]);

        $this->bank = BankAccount::query()->create([
            'bank_name' => 'مصرف محلي',
            'account_number' => 'LOC-1',
            'type' => BankAccountType::Local,
            'is_active' => true,
        ]);

        BankAccountBalance::query()->create([
            'bank_account_id' => $this->bank->id,
            'currency_id' => $this->lyd->id,
            'opening_balance' => 20,
        ]);

        $this->actingAs($this->cashier);
    }

    public function test_incoming_records_the_office_balance_and_pays_from_the_cashier_vault(): void
    {
        $deducted = InternalTransferService::post($this->incoming(100, 5, IncomingCommission::Deducted), $this->cashier);

        $this->assertSame(InternalTransferStatus::Delivered, $deducted->status);
        $this->assertEqualsWithDelta(5, (float) $deducted->profit, 0.001);
        $this->assertEqualsWithDelta(95, (float) $deducted->cash_amount, 0.001);
        $this->assertEqualsWithDelta(100, (float) $deducted->office_amount, 0.001);
        $this->assertEqualsWithDelta(905, $this->vaultLyd(), 0.001);
        $this->assertEqualsWithDelta(100, $this->officeBalance(), 0.001);

        $prepaid = InternalTransferService::post($this->incoming(40, 2, IncomingCommission::Prepaid), $this->cashier);

        $this->assertEqualsWithDelta(40, (float) $prepaid->cash_amount, 0.001);
        $this->assertEqualsWithDelta(42, (float) $prepaid->office_amount, 0.001);
        $this->assertEqualsWithDelta(2, (float) $prepaid->profit, 0.001);
        $this->assertEqualsWithDelta(865, $this->vaultLyd(), 0.001);
        $this->assertEqualsWithDelta(142, $this->officeBalance(), 0.001);
    }

    public function test_outgoing_cash_hits_the_vault_now_and_the_office_when_received(): void
    {
        $pending = InternalTransferService::post($this->outgoing(80, 4, OutgoingCommission::Deducted, InternalTransferSettlement::Cash), $this->cashier);

        $this->assertSame(InternalTransferStatus::Pending, $pending->status);
        $this->assertEqualsWithDelta(80, (float) $pending->cash_amount, 0.001);
        $this->assertEqualsWithDelta(-76, (float) $pending->office_amount, 0.001);
        $this->assertEqualsWithDelta(4, (float) $pending->profit, 0.001);
        $this->assertEqualsWithDelta(1080, $this->vaultLyd(), 0.001);
        $this->assertEqualsWithDelta(0, $this->officeBalance(), 0.001);

        $received = InternalTransferService::receive($pending, $this->cashier);

        $this->assertSame(InternalTransferStatus::Received, $received->status);
        $this->assertEqualsWithDelta(1080, $this->vaultLyd(), 0.001);
        $this->assertEqualsWithDelta(-76, $this->officeBalance(), 0.001);

        try {
            InternalTransferService::cancel($received, $this->cashier);
            $this->fail('توقع رفض إلغاء الحوالة المستلمة.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertEqualsWithDelta(-76, $this->officeBalance(), 0.001);
    }

    public function test_outgoing_bank_and_cancellation_keep_the_office_balance_until_receipt(): void
    {
        $pending = InternalTransferService::post($this->outgoing(50, 5, OutgoingCommission::Added, InternalTransferSettlement::Bank), $this->cashier);

        $this->assertEqualsWithDelta(55, (float) $pending->cash_amount, 0.001);
        $this->assertEqualsWithDelta(5, (float) $pending->profit, 0.001);
        $this->assertEqualsWithDelta(75, $this->bankLyd(), 0.001);
        $this->assertEqualsWithDelta(1000, $this->vaultLyd(), 0.001);
        $this->assertEqualsWithDelta(0, $this->officeBalance(), 0.001);

        InternalTransferService::receive($pending, $this->cashier);

        $this->assertEqualsWithDelta(-50, $this->officeBalance(), 0.001);
        $this->assertEqualsWithDelta(75, $this->bankLyd(), 0.001);

        $cancellable = InternalTransferService::post($this->outgoing(30, 0, OutgoingCommission::Deducted, InternalTransferSettlement::Cash), $this->cashier);

        $this->assertEqualsWithDelta(1030, $this->vaultLyd(), 0.001);
        $this->assertEqualsWithDelta(-50, $this->officeBalance(), 0.001);

        InternalTransferService::cancel($cancellable, $this->cashier);

        $this->assertEqualsWithDelta(1000, $this->vaultLyd(), 0.001);
        $this->assertEqualsWithDelta(-50, $this->officeBalance(), 0.001);
    }

    public function test_incoming_without_enough_custody_does_not_change_the_office(): void
    {
        CashierVaultBalance::query()
            ->where('cashier_vault_id', $this->vault->id)
            ->update(['current_balance' => 10]);

        try {
            InternalTransferService::post($this->incoming(100, 0, IncomingCommission::Deducted), $this->cashier);
            $this->fail('توقع رفض التسليم فوق رصيد العهدة.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertEqualsWithDelta(10, $this->vaultLyd(), 0.001);
        $this->assertEqualsWithDelta(0, $this->officeBalance(), 0.001);

        $manager = User::factory()->create([
            'role' => UserRole::Admin,
            'company' => 'sqlite',
        ]);

        try {
            InternalTransferService::post($this->incoming(10, 0, IncomingCommission::Deducted), $manager);
            $this->fail('توقع رفض غير الصراف.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('direction', $exception->errors());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function incoming(float $amount, float $commission, IncomingCommission $type): array
    {
        return [
            'direction' => InternalTransferDirection::Incoming->value,
            'transfer_office_id' => $this->office->id,
            'reference' => 'وارد-'.$amount,
            'sender_name' => 'أحمد',
            'sender_phone' => '0910000000',
            'sender_identity' => 'A-12',
            'amount' => $amount,
            'commission' => $commission,
            'commission_type' => $type->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outgoing(float $amount, float $commission, OutgoingCommission $type, InternalTransferSettlement $settlement): array
    {
        return [
            'direction' => InternalTransferDirection::Outgoing->value,
            'transfer_office_id' => $this->office->id,
            'reference' => 'صادر-'.$amount,
            'sender_name' => 'سالم',
            'sender_phone' => '0920000000',
            'sender_identity' => 'B-9',
            'amount' => $amount,
            'commission' => $commission,
            'commission_type' => $type->value,
            'settlement' => $settlement->value,
            'bank_account_id' => $this->bank->id,
            'recipient_name' => 'خالد',
            'recipient_identity' => 'C-3',
            'recipient_phone' => '0930000000',
        ];
    }

    private function vaultLyd(): float
    {
        return (float) CashierVaultBalance::query()
            ->where('cashier_vault_id', $this->vault->id)
            ->where('currency_id', $this->lyd->id)
            ->value('current_balance');
    }

    private function officeBalance(): float
    {
        return (float) $this->office->fresh()->balance;
    }

    private function bankLyd(): float
    {
        return (float) BankAccountBalance::query()
            ->where('bank_account_id', $this->bank->id)
            ->where('currency_id', $this->lyd->id)
            ->value('current_balance');
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
            $table->boolean('is_active')->default(true);
            $table->decimal('buy_cash_rate', 18, 6)->nullable();
            $table->decimal('buy_check_rate', 18, 6)->nullable();
            $table->decimal('sell_cash_rate', 18, 6)->nullable();
            $table->decimal('sell_check_rate', 18, 6)->nullable();
            $table->timestamps();
        });

        Schema::create('cashier_vaults', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
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
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('bank_name');
            $table->string('account_number')->unique();
            $table->string('type', 16);
            $table->boolean('is_active')->default(true);
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

        Schema::create('transfer_offices', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('balance', 18, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('internal_transfers', function (Blueprint $table): void {
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
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_identity', 255)->nullable();
            $table->string('recipient_phone', 30)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->timestamps();
        });
    }
}
