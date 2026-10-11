# Tunnel Publik Lokal — Cloudflare Quick Tunnel

Dua quick tunnel membuka Laravel dan Metabase ke internet tanpa akun Cloudflare.
Cocok untuk demo/UAT. **Bukan** untuk produksi: quick tunnel tidak punya
domain tetap, tanpa proteksi rate-limit Cloudflare, dan URL berubah tiap restart.

## Prasyarat

```bash
cloudflared --version   # jika belum ada: sudo apt install cloudflared
```

## Jalankan

Quick tunnel memakai satu proses per service; jalankan masing-masing di terminal
terpisah atau lewat `nohup`. Log tiap tunnel disimpan terpisah.

```bash
# Tunnel 1 — aplikasi Laravel (port 8000, host Sail)
cloudflared tunnel --no-autoupdate --url http://localhost:8000 \
  --logfile /tmp/tunnel-laravel.log

# Tunnel 2 — Metabase (port 3000, di-bind ke 127.0.0.1 oleh compose)
cloudflared tunnel --no-autoupdate --url http://localhost:3000 \
  --logfile /tmp/tunnel-metabase.log
```

URL `https://<random>.trycloudflare.com` muncul di log pada ~10 detik pertama:

```bash
grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' /tmp/tunnel-laravel.log | head -1
```

## Sinkronkan konfigurasi

URL tunnel berubah setiap start, jadi tiga nilai harus diset ulang:

| File | Nilai | Alasan |
|---|---|---|
| `dashboard-karangasem/.env` | `METABASE_SITE_URL` | URL iframe + target embed check dari container Laravel |
| `deploy/metabase/.env` | `METABASE_SITE_URL` | `MB_SITE_URL` Metabase untuk public link & embed |
| `deploy/metabase/.env` | `METABASE_SITE_URL` lalu `docker compose up -d` | |

`METABASE_SITE_URL` **wajib** URL publik, bukan `localhost`. Nilainya dipakai
dua pihak sekaligus: iframe di browser pengguna dan permintaan cURL dari
container Laravel (`artisan metabase:check-embedding`). URL yang hanya bisa
dijangkau browser membuat embed tampil tetapi pengecekan selalu gagal.

`APP_URL` boleh tetap `http://localhost:8000`: skema https sudah benar dari
header `X-Forwarded-Proto` milik tunnel, selama `TRUSTED_PROXIES` mendaftarkan
gateway Docker (lihat `docker/php/zz-trusted-proxies.ini`).

Setelah `.env` berubah:

```bash
cd dashboard-karangasem
vendor/bin/sail artisan config:clear
vendor/bin/sail artisan metabase:check-embedding   # wajib exit 0
```

## Verifikasi

```bash
# Laravel
curl -s -o /dev/null -w '%{http_code}\n' "$LARAVEL_URL/up"        # 200

# Halaman publik harus memuat iframe ke tunnel Metabase
curl -s "$LARAVEL_URL/" | grep -oE 'src="https://[^"]*trycloudflare[^"]*"'

# Metabase
curl -s "$METABASE_URL/api/health"                                # {"status":"ok"}

# CSP mengizinkan iframe dari origin Metabase
curl -sI "$LARAVEL_URL/" | grep -o 'frame-src[^;]*'
```

## Matikan tunnel

`Ctrl+C` pada kedua proses.

Untuk mengakhiri sesi demo dengan cepat:

```bash
pkill -f 'cloudflared tunnel'
```

## Catatan keamanan

`docs/runbook.md` §1 mengikat Metabase ke localhost/VPN supaya UI admin tidak
publik. Tunnel ini sengaja melanggarnya untuk keperluan demo. Setelah selesai:

- hentikan tunnel,
- set `METABASE_SITE_URL=http://localhost:3000` di kedua `.env`,
- pastikan tidak ada signup publik yang terbuka di instance Metabase.