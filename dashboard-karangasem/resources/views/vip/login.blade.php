<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login VIP — Dashboard Karangasem</title>
    <style>
        body { font-family: sans-serif; max-width: 420px; margin: 4rem auto; padding: 0 1rem; }
        label { display: block; margin-top: 1rem; }
        input { width: 100%; padding: .5rem; margin-top: .25rem; box-sizing: border-box; }
        button { margin-top: 1.5rem; padding: .6rem 1.2rem; }
        .error { color: #c00; font-size: .9rem; }
    </style>
</head>
<body>
    <h1>Login VIP</h1>

    @if ($errors->any())
        <div class="error">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('vip.login') }}">
        @csrf
        <label>Email
            <input type="email" name="email" value="{{ old('email') }}" required autofocus>
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <label><input type="checkbox" name="remember" value="1" style="width:auto"> Ingat saya</label>
        <button type="submit">Masuk</button>
    </form>
</body>
</html>
