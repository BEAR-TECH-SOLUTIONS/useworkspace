<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vault Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used exclusively for encrypting vault data (project keys,
    | entry data). It is separate from APP_KEY to limit blast radius —
    | if APP_KEY leaks (debug page, logs), vault data stays protected.
    |
    | Generate: php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
    |
    */

    'key' => env('VAULT_ENCRYPTION_KEY', env('APP_KEY')),

];
