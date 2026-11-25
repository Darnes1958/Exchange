<?php

namespace App\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Currency extends CompanyModel
{
    /**
     * @var list<string>
     */
    public const RATE_ATTRIBUTES = [
        'buy_cash_rate',
        'buy_check_rate',
        'sell_cash_rate',
        'sell_check_rate',
    ];

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
        'is_active',
        'buy_cash_rate',
        'buy_check_rate',
        'sell_cash_rate',
        'sell_check_rate',
    ];

    protected static function booted(): void
    {
        static::saving(function (Currency $currency): void {
            $ratesWereDirty = $currency->isDirty(self::RATE_ATTRIBUTES);

            if ($currency->isLibyanDinar()) {
                foreach (self::RATE_ATTRIBUTES as $attribute) {
                    $currency->{$attribute} = '1.000000';
                }

                $ratesWereDirty = false;
            }

            $masterDirty = ! $currency->exists || $currency->isDirty(['code', 'name', 'symbol', 'decimal_places', 'is_active']);

            if ($masterDirty && ! static::actorCanManage()) {
                throw new AuthorizationException('لا يمكن إدخال العملات أو تعديلها إلا للمدير العام أو المبرمج.');
            }

            if ($ratesWereDirty && ! static::actorCanUpdateRates()) {
                throw new AuthorizationException('لا يمكن تعديل أسعار العملات إلا للمدير.');
            }
        });

        static::updated(function (Currency $currency): void {
            if ($currency->isLibyanDinar() || ! $currency->wasChanged(self::RATE_ATTRIBUTES)) {
                return;
            }

            $user = Auth::user();
            $log = [
                'user_id' => $user instanceof User ? $user->id : null,
                'user_name' => $user instanceof User ? $user->name : null,
            ];

            foreach (self::RATE_ATTRIBUTES as $attribute) {
                $log[$attribute.'_before'] = $currency->getOriginal($attribute);
                $log[$attribute.'_after'] = $currency->{$attribute};
            }

            $currency->rateChanges()->create($log);
        });
    }

    protected static function actorCanManage(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return true;
        }

        return $user->canManageCurrencies();
    }

    protected static function actorCanUpdateRates(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->canUpdateCurrencyRates();
    }

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
            'buy_cash_rate' => 'decimal:6',
            'buy_check_rate' => 'decimal:6',
            'sell_cash_rate' => 'decimal:6',
            'sell_check_rate' => 'decimal:6',
        ];
    }

    public function isLibyanDinar(): bool
    {
        return strtoupper((string) $this->code) === 'LYD';
    }

    public function mainVaultBalances(): HasMany
    {
        return $this->hasMany(MainVaultBalance::class);
    }

    public function rateChanges(): HasMany
    {
        return $this->hasMany(CurrencyRateChange::class);
    }

    public static function imageUrlForCode(mixed $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }

        $code = strtolower(trim($code));

        if ($code === '' || preg_match('/^[a-z0-9]+$/', $code) !== 1) {
            return null;
        }

        $relative = 'images/currencies/'.$code.'.svg';

        if (! is_file(public_path($relative))) {
            return null;
        }

        return '/'.$relative;
    }

    public function imageUrl(): ?string
    {
        return static::imageUrlForCode($this->code);
    }

    public function decoratedLabel(string $label): string
    {
        $safeLabel = e($label);
        $url = $this->imageUrl();

        if ($url === null) {
            return $safeLabel;
        }

        return '<span style="display:inline-flex;align-items:center;gap:0.5rem;"><img src="'.e($url).'" alt="" width="28" height="20" style="width:28px;height:20px;object-fit:cover;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12);" /><span>'.$safeLabel.'</span></span>';
    }
}
