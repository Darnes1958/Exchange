<?php

namespace App\Filament\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait CashiersOnly
{
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->role === UserRole::Cashier
            && parent::canAccess();
    }
}
