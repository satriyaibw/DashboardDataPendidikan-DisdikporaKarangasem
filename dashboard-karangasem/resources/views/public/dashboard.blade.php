<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Pendidikan Karangasem</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; }
        header { padding: 0.75rem 1.25rem; border-bottom: 1px solid #e3e3e0; }
        h1 { font-size: 1.125rem; margin: 0; }
        iframe { display: block; width: 100%; height: calc(100vh - 3.25rem); border: 0; }
    </style>
</head>
<body>
    <header>
        <h1>Dashboard Pendidikan Kabupaten Karangasem</h1>
    </header>

    <iframe
        id="public-dashboard-frame"
        src="{{ $embedUrl }}"
        title="Dashboard publik data pendidikan Kabupaten Karangasem"
        loading="lazy"
        referrerpolicy="strict-origin-when-cross-origin"
        frameborder="0"
        allowfullscreen
    ></iframe>
</body>
</html>
