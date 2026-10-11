<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Fallback untuk Illuminate\Http\Middleware\TrustProxies. Nilai di sini dibaca
    | SAAT middleware menjalankan request, yaitu setelah .env selesai dimuat —
    | berbeda dari closure `withMiddleware()` di bootstrap/app.php yang berjalan
    | lebih awal, saat Laravel belum memuat .env.
    |
    | Middleware memakai `at:` bila closure itu berhasil membaca env, dan
    | jatuh ke nilai config ini bila tidak. Menuliskan daftar proxy di dua
    | tempat berisiko membuat hasilnya berbeda; karena itu config ini memakai
    | variabel env yang sama sehingga nilainya selalu identik.
    |
    | Mengosongkan berarti tidak ada proxy dipercaya: X-Forwarded-* dari klien
    | mana pun diabaikan dan IP diambil dari REMOTE_ADDR.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
