<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Akun Administrator Awal
    |--------------------------------------------------------------------------
    |
    | Kredensial akun admin pertama yang dibuat oleh DatabaseSeeder.
    | Dibaca dari env di config (bukan langsung di seeder) agar tetap
    | berfungsi setelah `php artisan config:cache`.
    |
    */

    'email' => env('ADMIN_EMAIL', 'admin@example.com'),

    'password' => env('ADMIN_PASSWORD'),

];
