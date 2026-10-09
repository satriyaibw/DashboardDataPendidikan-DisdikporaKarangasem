# Dashboard Data Pendidikan — Disdikpora Kabupaten Karangasem

Rencana dan implementasi **Dashboard Data Pendidikan Kabupaten Karangasem**.

- **Publik:** dashboard agregat non-sensitif tanpa login.
- **VIP:** dashboard analitik eksklusif dengan masa aktif berbatas waktu.
- **Admin:** pengelolaan akun VIP via panel admin.

## Tech Stack

| Layer | Teknologi |
| --- | --- |
| Database | PostgreSQL (existing Pusdatin, schema `datamart`/`dbo`/`ref`) |
| Analytics | Metabase (self-hosted, Docker) |
| Web wrapper | Laravel |
| Admin panel | Filament |
| RBAC | Spatie Permission |
| Embedding | Metabase Guest/Static Embedding (JWT, HS256) |

## Dokumen

- [`MasterPlan.md`](./MasterPlan.md) — rencana global final & panduan eksekusi.
- [`docs/data-dictionary.md`](./docs/data-dictionary.md) — data dictionary `backbone_client`.
- [`docs/security-checklist.md`](./docs/security-checklist.md) — checklist & prosedur verifikasi Fase 5 (keamanan, privasi, rotasi secret).
- [`docs/read-only-test.md`](./docs/read-only-test.md) — hasil uji akses `analis`.
- [`docs/migration-datamart.md`](./docs/migration-datamart.md) — prosedur cutover ke `datamart`.

## Fase 1 — Data Layer (Status: SELESAI)

- Schema kontrak `metrics` + view stabil (bentuk long):
  - `metrics.v_peserta_didik` — PD aktif per semester × kecamatan × jenjang × jk
  - `metrics.v_rombongan_belajar` — rombel & anggotanya per semester × kecamatan × tingkat
  - `metrics.v_ptk` — PTK terdaftar per tahun ajaran × kecamatan × jenis PTK
- DDL idempotent: `database/sql/metrics_views.sql` (GRANT ke role `analis`).
- Uji kesetaraan angka gateway cutover: `database/sql/test_equivalence.sql`.
- Materialized View: **belum diperlukan** — view kontrak saat ini ringan
  (hasil agregat kecil); bila kelak lambat, buat `mv_*.sql` dengan UNIQUE
  INDEX agar `REFRESH ... CONCURRENTLY` valid (lihat MasterPlan §5.5).

## Fase 2 — Metabase (Status: SELESAI)

- Metabase OSS + PostgreSQL metadata via Docker Compose (`deploy/metabase/docker-compose.yml`).
- 1 koneksi database: `backbone_admin` (admin_full, query native ke semua schema).
- 3 role akses:
  - **Admin**: full view, query native, read-only (no DELETE/EDIT).
  - **VIP**: lihat PII melalui dashboard VIP yang disiapkan admin, tidak bisa query native.
  - **Public**: agregat saja, tanpa PII.
- Role admin: `database/sql/admin_full_role.sql`.
- Dokumentasi: `docs/metabase-ids.md`.


## Fase 3 — Laravel + Filament (Status: SELESAI)

- Project Laravel 13 di subdirektori `dashboard-karangasem/` (PHP 8.5, `composer.lock` ter-commit).
- DB aplikasi terpisah `app_db` (user `app`, non-superuser); Laravel tidak menyentuh `backbone_client`.
- Spatie Permission: role `admin` & `vip`; seeder admin awal (email/password dari env `ADMIN_EMAIL`/`ADMIN_PASSWORD`).
- Filament 5 panel `/admin` dibatasi via `FilamentUser::canAccessPanel()` → role `admin`.
- Kolom user: `expires_at` (index), `is_active`, `vip_notes`.
- `UserResource`: CRUD user VIP, badge status Aktif/Kedaluwarsa/Nonaktif, action Perpanjang 30 hari & Aktif/Nonaktifkan, filter role & status.
- Middleware `CheckVipAccess` (`check.vip`) pada grup `/vip/*`: login → role vip → is_active → expires_at null/>now; gagal → logout + redirect + pesan; tanpa tulis DB.
- Login VIP custom minimal (`/login`, `/vip/login`, rate limit, throttle); health `GET /up`.
- Feature tests T2–T8: `php artisan test` (10 passed).

### Menjalankan (Docker / Laravel Sail)

```bash
cd dashboard-karangasem
cp .env.example .env   # isi ADMIN_PASSWORD
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan migrate --seed
```

- Web: http://localhost:8000 (`APP_PORT`), Postgres container di `localhost:5433` (`FORWARD_DB_PORT`).
- Test: `./vendor/bin/sail artisan test`.
- DB aplikasi: Postgres 18 container (service `pgsql`, db `app_db`, user `app`) — terpisah dari Postgres lokal backbone.
- Tanpa Docker tetap bisa: `php artisan serve` dengan `DB_HOST=127.0.0.1` mengarah ke Postgres lokal.

## Fase 4 — Signed Embedding (Status: SELESAI)

- Dashboard Publik di `/` (public embed, tanpa token) dan dashboard VIP di
  `/vip/dashboard` (signed embed, JWT HS256 TTL pendek, auto-refresh sebelum
  token kedaluwarsa).
- Endpoint JSON `/vip/embed-url` untuk memperbarui token tanpa reload halaman,
  dengan `Cache-Control: no-store, private` karena membawa token pada `src` iframe.
- `MetabaseCspHeaders` (alias `metabase.csp`, per-rute): `frame-src` hanya
  `'self'` + origin Metabase, plus `object-src 'none'; base-uri 'self';
  frame-ancestors 'none'`. Berlaku untuk `/` dan `/vip/*`, **tidak** untuk
  `/admin` agar panel Filament tidak rusak.
- `MetabaseEmbedService`: validasi fail-closed (URL absolut http/https, tanpa
  kredensial, secret ≥ 32 karakter, params skalar) dan TTL ≤ 0 mematikan refresh.
- Rate limit per rute: `/` dan `/vip/dashboard` 60/menit, `/vip/embed-url` 10/menit.

## Fase 5 — Privasi, Keamanan & Hardening (Status: SELESAI)

Lihat **`docs/security-checklist.md`** untuk detail lengkap & cara verifikasi.

**Header keamanan (WS-1)** — ekstraksi `MetabaseOrigin` (dipakai bersama oleh
CSP dan Permissions-Policy, tidak diduplikasi) dan middleware global
`SecurityHeaders`: `X-Content-Type-Options`, `Referrer-Policy`,
`Cross-Origin-Opener-Policy`, `Permissions-Policy` (`camera`/`microphone`/
`geolocation`/`payment`/`usb` dimatikan, `fullscreen` mengizinkan `self` +
origin Metabase — wajib, karena iframe Metabase cross-origin dan memakai
`allowfullscreen`), serta `X-Frame-Options: DENY` **hanya** bila respons belum
punya CSP (rute embed tetap mengandalkan `frame-ancestors 'none'` supaya tidak
ada dua kebijakan framing yang bertentangan). CSP global sengaja tidak dipasang —
akan mematikan skrip inline Livewire/Alpine pada panel Filament.

**HTTPS & HSTS (WS-2)** — `force_https`, `hsts_enabled`, `hsts_max_age`
(default nonaktif), `URL::forceScheme('https')`, dan middleware
`StrictTransportSecurity` yang **hanya** mengirim header pada request HTTPS +
`HSTS_ENABLED=true` agar domain tak pernah terkunci HTTPS sebelum TLS siap.

**Cookie (WS-3)** — `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE=lax`,
`SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT` didokumentasikan di `.env.example`.
`config/session.php` tidak diubah (sudah membaca env tersebut).

**Kebijakan password (WS-4)** — `App\Services\PasswordPolicy`: minimal 12
karakter, huruf besar/kecil, angka. Satu definisi dipakai bersama form Filament
dan `DatabaseSeeder` sehingga keduanya tidak pernah berbeda; `ADMIN_PASSWORD`
yang lemah menggagalkan `db:seed` dengan pesan tersurat (fail-closed).
Hashing tetap memakai cast bawaan `'password' => 'hashed'` (idempoten lewat
penjaga `Hash::isHashed()` — jangan menambahkan `Hash::make()`).

**MFA panel admin (WS-11)** — `AdminPanelProvider` mewajibkan multi-factor
authentication TOTP (`isRequired: true`) dengan kode pemulihan. `User`
mengimplementasikan `HasAppAuthentication` + `HasAppAuthenticationRecovery`;
secret terenkripsi dan tersembunyi dari serialisasi. Login VIP tidak terpengaruh.
Butuh cache store dengan atomic lock (`database`/`redis`) — `database` sudah memenuhi.

**Audit log (WS-5)** — migrasi `admin_activity_logs` + `UserObserver` +
`AdminActivityLogger`: setiap buat/ubah/hapus user VIP tercatat dengan
`actor_id`, `action`, perubahan `before`/`after`, `ip`, `user_agent`. Penyaringan
kolom memakai **whitelist** (deny-by-default), bukan blacklist — atribut sensitif
yang baru ditambahkan kelak tidak akan ikut tercatat hanya karena lupa
memperbarui daftar hitam. Kegagalan penulisan audit tidak pernah menggagalkan
aksi admin. `audit:prune --days=365` menyiapkan retensi (penjadwalan di Fase 6).

**Verifikasi PII (WS-6)** — `vendor/bin/sail artisan security:scan-pii` login ke
API Metabase (session id hanya di memori, tak pernah ditulis/dicetak), memindai
query native & field MBQL, menulis laporan ke `storage/app/security/`, dan keluar
dengan kode non-nol bila menemukan kolom PII pada dashboard Publik/VIP.
Pencocokan berbasis **batas kata** (`\bnama\b`), bukan substring, agar
`nama_kecamatan` & `nama_sekolah` (agregat sah) tidak ikut ditandai.
Model akses backbone tetap penuh; privasi dijaga di lapisan pemilihan dashboard
oleh admin (lihat `docs/security-checklist.md` §1).

**Pembatasan UI Metabase (WS-7)** — `deploy/nginx/metabase.conf` hanya
melayani `/public/`, `/embed/`, `/api/health`; sisanya `allow <IP VPN/admin>`
+ `deny all`. `deploy/nginx/dashboard.conf` melakukan redirect HTTP→HTTPS.
Keduanya **file contoh** dengan domain placeholder — tidak mengubah runtime
yang sedang berjalan. HSTS hanya di satu tempat (middleware Laravel).

**Verifikasi secret (WS-8)** — `vendor/bin/sail artisan metabase:check-embedding`
memvalidasi konfigurasi lewat `MetabaseEmbedService` (tanpa menduplikasi aturan)
lalu memuat satu URL bertanda tangan dan melaporkan status; secret & token tidak
pernah ikut tercetak. Prosedur rotasi + peringatan `MB_ENCRYPTION_SECRET_KEY`
terdokumentasi di `docs/metabase-ids.md`.

**CI (WS-9)** — `.github/workflows/security.yml`: `composer audit` + seluruh test
+ `pint --test`, dijalankan pada push/PR dan jadwal mingguan. Tanpa dependensi
runtime baru.

**Verifikasi:** `dashboard-karangasem` punya **138 test** (T20–T32 sesuai
matriks Issue #9). Jalankan:

```bash
cd dashboard-karangasem
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --format agent
vendor/bin/sail composer audit
vendor/bin/sail artisan security:scan-pii        # isi METABASE_ADMIN_* dulu
vendor/bin/sail artisan metabase:check-embedding
```

## Fase 6/7 — belum dimulai

- **Fase 6:** `routes/console.php` (`vip:deactivate-expired`, `audit:prune`),
  backup, observability, `docs/runbook.md`.
- **Fase 7:** UAT lintas fase, `docs/uat.md`.
