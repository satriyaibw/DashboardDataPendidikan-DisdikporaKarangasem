<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Konfigurasi Embedding Metabase
    |--------------------------------------------------------------------------
    |
    | Semua nilai kredensial WAJIB berasal dari environment (.env). Tidak ada
    | default untuk URL/secret agar salah konfigurasi langsung gagal (fail-fast)
    | alih-alih diam-diam menampilkan dashboard dari instance yang salah.
    |
    */

    // URL penuh instance Metabase, tanpa trailing slash. Hanya http/https.
    'site_url' => env('METABASE_SITE_URL'),

    // Secret embedding Metabase (MB_EMBEDDING_SECRET_KEY). Wajib identik dengan
    // Metabase dan minimal 32 karakter (HS256 membutuhkan kunci 256 bit).
    'embedding_secret' => env('METABASE_EMBEDDING_SECRET'),

    // Minimal panjang secret yang diterima untuk penandatanganan HS256.
    'min_secret_length' => 32,

    // ID dashboard public embedding (tanpa login) — lihat docs/metabase-ids.md.
    'public_dashboard_id' => env('METABASE_PUBLIC_DASHBOARD_ID'),

    // ID dashboard signed embedding (VIP, JWT HS256) — lihat docs/metabase-ids.md.
    'vip_dashboard_id' => env('METABASE_VIP_DASHBOARD_ID'),

    // Masa berlaku token signed embedding, dalam detik (default 600 = 10 menit).
    // Nilai <= 0 akan menghasilkan token yang langsung kedaluwarsa dan
    // menonaktifkan refresh otomatis pada halaman VIP.
    'embed_ttl' => (int) env('METABASE_EMBED_TTL', 600),

    // ID dashboard admin (opsional). Tidak di-embed oleh aplikasi; dipakai
    // `artisan security:scan-pii` bila ingin ikut diperiksa.
    'admin_dashboard_id' => env('METABASE_ADMIN_DASHBOARD_ID'),

    /*
    |--------------------------------------------------------------------------
    | Kredensial Admin Metabase (hanya untuk skrip verifikasi)
    |--------------------------------------------------------------------------
    |
    | Dipakai `artisan security:scan-pii` untuk login ke API Metabase agar
    | dapat membaca definisi kartu. Session id yang dihasilkan hanya disimpan
    | di memori: tidak pernah dicetak, ditulis ke log, atau masuk laporan.
    |
    | Metabase API memakai field `username`; isi dengan email admin.
    |
    */

    'admin_email' => env('METABASE_ADMIN_EMAIL'),

    'admin_password' => env('METABASE_ADMIN_PASSWORD'),
];
