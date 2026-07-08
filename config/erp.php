<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | ERP Basic Auth Fallback
    |--------------------------------------------------------------------------
    |
    | Awaryjne konto administratora używane, gdy logowanie z tabeli users
    | nie powiedzie się. Wartości muszą być czytane przez config(), nie env(),
    | bo po `php artisan config:cache` env() nie zwraca wartości z .env.
    |
    */

    'basic_user' => env('ERP_BASIC_USER', ''),
    'basic_password' => env('ERP_BASIC_PASSWORD', ''),
    'fallback_email' => env('ERP_FALLBACK_EMAIL', ''),

];
