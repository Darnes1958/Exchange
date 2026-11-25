<?php

namespace App\Database\Migrations\Concerns;

trait MigratesCompanyDatabases
{
    /**
     * @return list<string>
     */
    protected function companyConnections(): array
    {
        if (app()->runningUnitTests() || app()->environment('testing')) {
            return [];
        }

        return array_values(array_filter(
            config('exchange.company_connections', []),
            fn (mixed $name): bool => is_string($name) && $name !== '' && config("database.connections.{$name}") !== null,
        ));
    }

    protected function onEachCompanyConnection(callable $callback): void
    {
        foreach ($this->companyConnections() as $connection) {
            $callback($connection);
        }
    }
}
