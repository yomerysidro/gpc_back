<?php

return [

    /*
    |--------------------------------------------------------------------------
    | JWT
    |--------------------------------------------------------------------------
    |
    | Clave HS256 con la que se firman los tokens de acceso y su duración en
    | minutos. El token viaja en la cookie httpOnly "access_token".
    |
    */

    'secret' => env('JWT_SECRET'),

    'ttl' => (int) env('JWT_TTL', 1440),

];
