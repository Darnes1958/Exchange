<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class MainVaultBalance extends CompanyModel
{
    public bool $recalculatingCostPrice = false;

    protected $fillable = [
        'main_vault_id',
        'currency_id',
        'opening_balance',
        'cost_price',
    ];

    protected static function booted(): void
    {
        static::creating(function (MainVaultBalance $balance): void {
            if (! static::actorCanAdjustCarriedBalance()) {
                $balance->opening_balance = 0;
            }

            $balance->current_balance = $balance->opening_balance ?? 0;

            if ($balance->isLibyanDinar()) {
                $balance->cost_price = '1.000000';
            }
        });

        static::updating(function (MainVaultBalance $balance): void {
            if ($balance->isDirty('opening_balance') && ! static::actorCanAdjustCarriedBalance()) {
                $balance->opening_balance = $balance->getOriginal('opening_balance');
            }

            $openingChanged = static::openingBalanceChanged($balance);

            if ($balance->isDirty('cost_price')) {
                static::guardCostPrice($balance, $openingChanged);
            }

            if (! $openingChanged) {
                return;
            }

            $movements = (float) $balance->getOriginal('current_balance') - (float) $balance->getOriginal('opening_balance');
            $balance->current_balance = number_format((float) $balance->opening_balance + $movements, 3, '.', '');
        });
    }

    /**
     * يحدّث سعر التكلفة بالمتوسط المرجّح قبل إضافة كمية الشراء إلى الرصيد الحالي.
     */
    public function applyPurchaseCost(float|string $quantity, float|string $unitCostInLyd): void
    {
        if ($this->isLibyanDinar()) {
            $this->recalculatingCostPrice = true;
            $this->cost_price = '1.000000';

            return;
        }

        $purchased = (float) $quantity;

        if ($purchased <= 0) {
            return;
        }

        $held = (float) $this->current_balance;
        $total = $held + $purchased;
        $average = (($held * (float) $this->cost_price) + ($purchased * (float) $unitCostInLyd)) / $total;

        $this->recalculatingCostPrice = true;
        $this->cost_price = number_format($average, 6, '.', '');
    }

    protected static function guardCostPrice(MainVaultBalance $balance, bool $openingChanged): void
    {
        if ($balance->recalculatingCostPrice) {
            $balance->recalculatingCostPrice = false;

            return;
        }

        if ($balance->isLibyanDinar()) {
            $balance->cost_price = '1.000000';

            return;
        }

        if ($openingChanged && static::actorCanAdjustCarriedBalance()) {
            if (blank($balance->cost_price) && filled($balance->getOriginal('cost_price'))) {
                $balance->cost_price = $balance->getOriginal('cost_price');
            }

            return;
        }

        if (filled($balance->getOriginal('cost_price'))) {
            $balance->cost_price = $balance->getOriginal('cost_price');
        }
    }

    protected static function openingBalanceChanged(MainVaultBalance $balance): bool
    {
        if (! $balance->isDirty('opening_balance')) {
            return false;
        }

        return round((float) $balance->opening_balance, 3) !== round((float) $balance->getOriginal('opening_balance'), 3);
    }

    protected static function actorCanAdjustCarriedBalance(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return true;
        }

        return $user->canAdjustCarriedBalance();
    }

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:3',
            'current_balance' => 'decimal:3',
            'cost_price' => 'decimal:6',
        ];
    }

    public function isLibyanDinar(): bool
    {
        $code = $this->currency?->code;

        if (! is_string($code) && filled($this->currency_id)) {
            $code = Currency::query()->whereKey($this->currency_id)->value('code');
        }

        return strtoupper((string) $code) === 'LYD';
    }

    public function mainVault(): BelongsTo
    {
        return $this->belongsTo(MainVault::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
