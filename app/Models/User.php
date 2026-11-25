<?php

namespace App\Models;

use App\Enums\BranchMode;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property string|null $company
 * @property int|null $branch_id
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'company', 'branch_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $actor = Auth::user();

            if (! $actor instanceof User || ! $user->isDirty('role')) {
                return;
            }

            $original = $user->getOriginal('role');
            $originalRole = $original instanceof UserRole
                ? $original
                : UserRole::tryFrom((string) $original);

            if ($user->exists && $originalRole instanceof UserRole && ! $originalRole->canBeAssignedBy($actor->role)) {
                $user->role = $originalRole;

                return;
            }

            if (! $user->role instanceof UserRole || ! $user->role->canBeAssignedBy($actor->role)) {
                $user->role = $user->exists ? $originalRole : UserRole::Cashier;
            }
        });

        static::saving(function (User $user): void {
            $originalRole = $user->originalRole();
            $originalBranchId = $user->getOriginal('branch_id');
            $originalCompany = $user->getOriginal('company');

            if (! $user->role instanceof UserRole || ! $user->worksInBranch()) {
                $user->branch_id = null;
            } else {
                $connection = is_string($user->company) && $user->company !== '' ? $user->company : null;

                if ($connection !== null && config("database.connections.{$connection}") !== null && Schema::connection($connection)->hasTable('company_settings')) {
                    $mode = CompanySetting::on($connection)->value('branch_mode');
                    $single = $mode === BranchMode::Single->value || $mode === BranchMode::Single;

                    if ($single) {
                        $branchId = Branch::on($connection)->orderBy('id')->value('id');

                        if (! $branchId) {
                            throw ValidationException::withMessages([
                                'branch_id' => 'لا يوجد فرع للشركة.',
                            ]);
                        }

                        $user->branch_id = $branchId;
                    } elseif (blank($user->branch_id)) {
                        throw ValidationException::withMessages([
                            'branch_id' => 'حدد الفرع.',
                        ]);
                    }
                }
            }

            if ($user->leavesBranchWithoutManager($originalRole, $originalBranchId, $originalCompany)) {
                throw ValidationException::withMessages([
                    'role' => 'لكل فرع مدير واحد على الأقل، ولا يمكن ترك الفرع بلا مدير.',
                ]);
            }
        });

        static::saved(function (User $user): void {
            if ($user->role !== UserRole::Cashier || blank($user->branch_id) || ! $user->wasChanged('branch_id')) {
                return;
            }

            $connection = is_string($user->company) && $user->company !== '' ? $user->company : null;

            if ($connection === null || config("database.connections.{$connection}") === null) {
                return;
            }

            if (! Schema::connection($connection)->hasTable('cashier_vaults')) {
                return;
            }

            CashierVault::on($connection)
                ->where('user_id', $user->id)
                ->update(['branch_id' => $user->branch_id]);
        });

        static::deleting(function (User $user): void {
            if ($user->role !== UserRole::Admin || blank($user->branch_id)) {
                return;
            }

            if (! static::branchHasAnotherManager($user->company, $user->branch_id, (int) $user->id)) {
                throw ValidationException::withMessages([
                    'role' => 'لكل فرع مدير واحد على الأقل، ولا يمكن حذف آخر مدير.',
                ]);
            }
        });
    }

    public function leavesBranchWithoutManager(mixed $originalRole, mixed $originalBranchId, mixed $originalCompany): bool
    {
        if (! $this->exists || ! $originalRole instanceof UserRole || $originalRole !== UserRole::Admin || blank($originalBranchId)) {
            return false;
        }

        $company = is_string($originalCompany) && $originalCompany !== '' ? $originalCompany : $this->company;
        $stillManager = $this->role === UserRole::Admin
            && (string) $this->branch_id === (string) $originalBranchId
            && (string) $this->company === (string) $company;

        if ($stillManager) {
            return false;
        }

        return ! static::branchHasAnotherManager($company, $originalBranchId, $this->id);
    }

    protected function originalRole(): ?UserRole
    {
        $original = $this->getOriginal('role');

        if ($original instanceof UserRole) {
            return $original;
        }

        if (is_string($original)) {
            return UserRole::tryFrom($original);
        }

        return null;
    }

    protected static function branchHasAnotherManager(mixed $company, mixed $branchId, int $exceptUserId): bool
    {
        return static::query()
            ->where('role', UserRole::Admin->value)
            ->where('branch_id', $branchId)
            ->when(
                is_string($company) && $company !== '',
                fn ($query) => $query->where('company', $company),
            )
            ->whereKeyNot($exceptUserId)
            ->exists();
    }

    public function worksInBranch(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Cashier], true);
    }

    public function getConnectionName(): ?string
    {
        return (string) config('exchange.central_connection', config('database.default'));
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, [
            UserRole::Prog,
            UserRole::SupperAdmin,
            UserRole::Admin,
            UserRole::Cashier,
        ], true);
    }

    public function canAdjustCarriedBalance(): bool
    {
        return in_array($this->role, [
            UserRole::Prog,
            UserRole::SupperAdmin,
        ], true);
    }

    public function canManageCompanySettings(): bool
    {
        return in_array($this->role, [
            UserRole::Prog,
            UserRole::SupperAdmin,
        ], true);
    }

    public function canManageCurrencies(): bool
    {
        return in_array($this->role, [
            UserRole::Prog,
            UserRole::SupperAdmin,
        ], true);
    }

    public function canUpdateCurrencyRates(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function companyRecord(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company', 'connection_name');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
