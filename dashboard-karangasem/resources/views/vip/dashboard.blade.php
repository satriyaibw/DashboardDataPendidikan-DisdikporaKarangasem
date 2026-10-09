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
            var refreshIntervalMs = {{ $refreshIntervalSeconds }} * 1000;
            var endpoint = @json(route('vip.embed-url'));

            if (!frame || refreshIntervalMs <= 0) {
                return;
            }

            setInterval(function () {
                if (document.visibilityState !== 'visible') {
                    return;
                }

                fetch(endpoint, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                }).then(function (response) {
                    return response.ok ? response.json() : null;
                }).then(function (data) {
                    if (data && data.embed_url) {
                        frame.src = data.embed_url;
                    }
                }).catch(function () {
                    // Diamkan: percobaan berikutnya pada interval berikutnya.
                });
            }, refreshIntervalMs);
        })();
    </script>
</body>
</html>
