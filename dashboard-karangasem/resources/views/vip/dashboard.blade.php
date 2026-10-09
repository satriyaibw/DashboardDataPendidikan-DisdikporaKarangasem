<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard VIP</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1.25rem; border-bottom: 1px solid #e3e3e0; }
        h1 { font-size: 1.125rem; margin: 0; }
        .user { font-size: 0.875rem; color: #706f6c; }
        form { margin: 0; }
        button { cursor: pointer; padding: 0.35rem 0.9rem; border: 1px solid #19140035; border-radius: 0.25rem; background: #fff; font-size: 0.875rem; }
        iframe { display: block; width: 100%; height: calc(100vh - 3.25rem); border: 0; }
    </style>
</head>
<body>
    <header>
        <h1>Dashboard VIP</h1>
        <span class="user">{{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('vip.logout') }}">
            @csrf
            <button type="submit">Keluar</button>
        </form>
    </header>

    <iframe
        id="vip-dashboard-frame"
        src="{{ $embedUrl }}"
        title="Dashboard VIP data pendidikan Kabupaten Karangasem"
        loading="lazy"
        referrerpolicy="strict-origin-when-cross-origin"
        frameborder="0"
        allowfullscreen
    ></iframe>

    <script>
        (function () {
            var frame = document.getElementById('vip-dashboard-frame');
            var endpoint = @json(route('vip.embed-url'));
            var ttlSeconds = {{ $embedTtlSeconds }};
            var refreshIntervalSeconds = {{ $refreshIntervalSeconds }};

            // TTL tidak masuk akar: jangan jadwalkan refresh apa pun.
            if (!frame || refreshIntervalSeconds <= 0) {
                return;
            }

            // Sisa waktu sebelum token dianggap "hampir kedaluwarsa".
            var safetyMarginSeconds = Math.max(15, Math.round(ttlSeconds * 0.05));

            var timer = null;
            var inFlight = null;

            /**
             * Baca klaim "exp" (detik UNIX) dari segmen payload JWT pada URL embed.
             * Mengembalikan null bila token tidak dapat dibaca.
             */
            function tokenExpiry(url) {
                var match = /\/embed\/dashboard\/([^?#]+)/.exec(url);

                if (!match) {
                    return null;
                }

                var segments = match[1].split('.');

                if (segments.length !== 3) {
                    return null;
                }

                // atob() menolak base64url tanpa padding.
                var base64 = segments[1].replace(/-/g, '+').replace(/_/g, '/');

                while (base64.length % 4 !== 0) {
                    base64 += '=';
                }

                try {
                    var payload = JSON.parse(atob(base64));

                    return typeof payload.exp === 'number' ? payload.exp : null;
                } catch (error) {
                    return null;
                }
            }

            /**
             * Jeda sampai refresh berikutnya: paling lama satu kali polling penuh,
             * dan dipercepat hanya bila token sudah mendekati kedaluwarsa. Dengan
             * begitu iframe tidak dimuat ulang sebelum benar-benar diperlukan,
             * sehingga pilihan filter pengguna di Metabase tetap terjaga.
             */
            function millisecondsUntilRefresh() {
                var expiry = tokenExpiry(frame.src);

                if (expiry === null) {
                    return refreshIntervalSeconds * 1000;
                }

                return Math.min(
                    refreshIntervalSeconds * 1000,
                    Math.max(0, expiry * 1000 - Date.now() - safetyMarginSeconds * 1000)
                );
            }

            function schedule() {
                if (timer) {
                    clearTimeout(timer);
                }

                // Jangan pernah polling lebih cepat dari satu detik.
                timer = setTimeout(refresh, Math.max(1000, millisecondsUntilRefresh()));
            }

            function refresh() {
                // Satu permintaan dijalankan pada satu waktu.
                if (inFlight) {
                    return;
                }

                // Tab tersembunyi: tunda, jangan menumpuk permintaan token.
                if (document.visibilityState !== 'visible') {
                    schedule();
                    return;
                }

                inFlight = new AbortController();

                fetch(endpoint, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    signal: inFlight.signal
                }).then(function (response) {
                    return response.ok ? response.json() : null;
                }).then(function (data) {
                    inFlight = null;

                    if (data && data.embed_url && data.embed_url !== frame.src) {
                        frame.src = data.embed_url;
                    }

                    schedule();
                }).catch(function () {
                    inFlight = null;
                    // Diamkan kegagalan; percobaan berikutnya pada jadwal berikutnya.
                    schedule();
                });
            }

            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible') {
                    refresh();
                }
            });

            schedule();
        })();
    </script>
</body>
</html>
