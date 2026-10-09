<?php

return [
    // URL penuh instance Metabase, tanpa trailing slash.
    'site_url' => env('METABASE_SITE_URL', 'http://localhost:3000'),

    // Secret embedding Metabase (MB_EMBEDDING_SECRET_KEY). Wajib sama dengan Metabase.
    'embedding_secret' => env('METABASE_EMBEDDING_SECRET'),

    // ID dashboard public embed (tanpa login) — lihat docs/metabase-ids.md.
    'public_dashboard_id' => env('METABASE_PUBLIC_DASHBOARD_ID'),

    // ID dashboard signed embed (VIP, JWT HS256) — lihat docs/metabase-ids.md.
    'vip_dashboard_id' => env('METABASE_VIP_DASHBOARD_ID'),

    // Masa berlaku token signed embed, dalam detik (default 600 = 10 menit).
    'embed_ttl' => (int) env('METABASE_EMBED_TTL', 600),
];
