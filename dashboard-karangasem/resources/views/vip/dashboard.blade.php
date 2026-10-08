<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Dashboard VIP</title>
</head>
<body>
    <h1>Dashboard VIP</h1>
    <p>Halo, {{ auth()->user()->name }}. Embedding Metabase akan ditambahkan pada Fase 4.</p>
    <form method="POST" action="{{ route('vip.logout') }}">@csrf<button>Keluar</button></form>
</body>
</html>
