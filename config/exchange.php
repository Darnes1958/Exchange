<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central database
    |--------------------------------------------------------------------------
    |
    | Users and the company registry live here. Each exchange company has its
    | own SQL Server database, named by the connection in company_connections.
    |
    */

    'central_connection' => env('DB_CONNECTION', 'sqlsrv'),

    /*
    |--------------------------------------------------------------------------
    | Company databases
    |--------------------------------------------------------------------------
    |
    | Each name must match a connection in config/database.php and the value
    | stored in users.company.
    |
    */

    'company_connections' => [
        'testEXC',
    ],

];
