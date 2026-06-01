<?php

/**
 * Mirrors Laravel's default config/hashing.php. Share-link passphrase
 * hashing (Universal Share Links plan §9) does NOT live here — it is
 * implemented as a dedicated ShareLinkPasswordHasher service so the
 * global `bcrypt` user-password driver stays untouched.
 */
return [

    'driver' => env('HASH_DRIVER', 'bcrypt'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 4,
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
