<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local demo account
    |--------------------------------------------------------------------------
    |
    | Used only by DatabaseSeeder. Change the password before this database
    | is reachable by anyone except you.
    |
    */

    'demo_user' => [
        'name' => env('SEED_USER_NAME', 'WorkState Demo'),
        'email' => env('SEED_USER_EMAIL', 'demo@workstate.test'),
        'password' => env('SEED_USER_PASSWORD', 'password'),
    ],

];
