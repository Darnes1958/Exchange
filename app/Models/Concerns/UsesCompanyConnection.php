<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

trait UsesCompanyConnection
{
    public function getConnectionName(): ?string
    {
        if (is_string($this->connection) && $this->connection !== '') {
            return $this->connection;
        }

        $company = Auth::user()?->company;

        if (is_string($company) && $company !== '' && config("database.connections.{$company}")) {
            return $company;
        }

        return parent::getConnectionName();
    }
}
