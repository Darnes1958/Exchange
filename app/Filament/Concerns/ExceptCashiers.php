<?php

namespace App\Filament\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait ExceptCashiers
{
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user instanceof User && $user->role === UserRole::Cashier) {
            return false;
        }

        return parent::canAccess();
    }
}
