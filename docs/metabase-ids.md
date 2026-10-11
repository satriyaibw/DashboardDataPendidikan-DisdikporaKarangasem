# Metabase Configuration — Dashboard IDs & Settings

> **Fase 2 — Metabase** (Issue #3)
> Terakhir update: 2026-10-08

## Site URL

```
METABASE_SITE_URL=http://localhost:3000
```

## Dashboard IDs

| Dashboard | ID | UUID (public embed) | Akses | Keterangan |
|---|---|---|---|---|
| **Publik** | `5` | `65fb774f-493a-4bbc-b438-34bf5de4c174` | Public (tanpa login) | Agregat tinggi, tanpa PII |
| **VIP** | `6` | — | User VIP (login, signed embed) | Agregat detail + PII |

> **Penting — public embed memakai UUID, bukan ID numerik.**
> Endpoint `/public/dashboard/{id}` milik Metabase hanya menerima `public_uuid`.
> Memakai ID numerik membuat iframe menampilkan "Unauthenticated" alih-alih
> dashboard. Aplikasi membacanya dari `METABASE_PUBLIC_DASHBOARD_UUID`.
>
> ID numerik tetap dipakai untuk hal lain (mis. `artisan security:scan-pii`
> yang memanggil API sebagai admin terotorisasi) lewat
> `METABASE_PUBLIC_DASHBOARD_ID`.
>
> UUID freshly obtained dengan:
> `SELECT public_uuid FROM report_dashboard WHERE id = <METABASE_PUBLIC_DASHBOARD_ID>;`

Kartu pada kedua dashboard:

| Dashboard | Kartu | Sumber |
|---|---|---|
| Publik (5) | P1–P7 | `metrics.*` via `backbone_admin` (database 2) |
| VIP (6) | V1–V6 | `metrics.*` via `backbone_admin` (database 2) |

> Kartu contoh bawaan Metabase (Sample Database, H2, `database_id` 1) berada di
> collection `Examples`/`E-commerce`. Kartu tersebut **bukan** bagian dashboard
> 5/6 dan tidak muncul di sana.

## Database Connections

| Koneksi | ID | User | Schema | Query Native | Dipakai Untuk |
|---|---|---|---|---|---|
| `backbone_admin` | `2` | `admin_full` | `dbo`, `ref`, `datamart`, `metrics` | **Ya** | Semua dashboard (Publik, VIP, Admin) |

**Catatan:** Hanya ada 1 koneksi database. Semua dashboard menggunakan koneksi yang sama. Admin bisa query native ke semua schema (`dbo`, `ref`, `datamart`, `metrics`). User VIP/Public hanya bisa melihat dashboard yang sudah disiapkan oleh admin.

## Kontrol Akses

| Role | Akses | Keterangan |
|---|---|---|
| **Public** | Hanya dashboard Publik | Tidak perlu login, hanya melihat dashboard yang disiapkan admin (tanpa PII) |
| **VIP** | Hanya dashboard VIP | Login, hanya melihat dashboard yang disiapkan admin (dengan PII) |
| **Admin** | Full | Bisa query native ke semua schema (`dbo`, `ref`, `datamart`, `metrics`) |

**Kontrol akses dilakukan melalui permission per dashboard/collection, bukan per koneksi database.**

## Caching

- **Default TTL:** 180 menit (3 jam) — dikonfigurasi per dashboard/pertanyaan
- **Cache diaktifkan:** Admin → Settings → Caching
- **Catatan:** Metabase tidak memiliki env var global cache TTL; atur via UI/config file
- **Untuk kartu berat:** Set cache kustom atau scheduled refresh

## Embedding

- **Static/Guest Embedding:** Diaktifkan untuk Dashboard VIP
- **Public link/embed:** Hanya untuk Dashboard Publik, memakai `public_uuid`
- **Embedding Secret Key:** Disimpan di `METABASE_EMBEDDING_SECRET` (Laravel) dan `MB_EMBEDDING_SECRET_KEY` (Metabase) — **harus sama**

## Prosedur Rotasi Secret

1. Generate secret baru: `openssl rand -hex 32`
2. Update `MB_EMBEDDING_SECRET_KEY` di `deploy/metabase/.env`
3. Update `METABASE_EMBEDDING_SECRET` di `.env` Laravel (Fase 4)
4. Restart Metabase: `docker compose -f deploy/metabase/docker-compose.yml restart`
5. **Verifikasi rotasi berhasil:**
   ```bash
   cd dashboard-karangasem
   vendor/bin/sail artisan metabase:check-embedding
   ```
   Perintah ini memvalidasi konfigurasi lewat service yang sama dengan yang
   dipakai produksi (tanpa menduplikasi aturan validasi), lalu memuat satu URL
   bertanda tangan dan melaporkan status HTTP. Exit code `1` bila konfigurasi
   tidak valid **atau** Metabase menjawab 4xx/5xx, dan `0` hanya bila
   Metabase menjawab 2xx/3xx. Respons 401/403 adalah tanda paling umum bahwa
   embedding belum aktif atau secret tidak identik dengan
   `MB_EMBEDDING_SECRET_KEY`. Secret dan token **tidak pernah ikut tercetak**
   di output maupun log.
6. Uji manual: buka halaman publik dan halaman VIP, pastikan iframe dashboard
   tetap tampil (bukan "Embedding is not enabled for this object" / 401).

### Jangan rotasi `MB_ENCRYPTION_SECRET_KEY` tanpa kebutuhan

`MB_ENCRYPTION_SECRET_KEY` mengenkripsi **kredensial koneksi database** yang
tersimpan di metadata Metabase. Merotasi kunci enkripsi membuat seluruh
kredensial koneksi harus di-set ulang lewat Admin Panel (sudah diperingatkan di
`deploy/metabase/docker-compose.yml`). Rotasi secret **embedding** tidak
membutuhkan hal ini — keduanya berbeda dan tidak perlu dirotasi bersamaan.

### Catatan soal token

JWT dipakai untuk **penandatanganan (signed)**, bukan enkripsi: siapa pun dapat
membaca isi `resource`, `params`, dan `exp`. Karena itu TTL sengaja pendek
(METABASE_EMBED_TTL, default 600 detik) dan halaman VIP mengirim
`Cache-Control: no-store, private` agar token tidak tersimpan di cache browser.

## Troubleshooting

| Gejala | Kemungkinan penyebab | Aksi |
|---|---|---|
| Iframe publik menampilkan "Unauthenticated" | `METABASE_PUBLIC_DASHBOARD_UUID` kosong/salah, atau diisi ID numerik | Public embed wajib UUID, bukan ID. Ambil ulang: `SELECT public_uuid FROM report_dashboard WHERE id = <id>;` |
| Login VIP mendarat di `/admin` lalu 403 | Outdated `url.intended` di sesi dari kunjungan ke panel | Akses lagi dari `/login`; memfilter ke area `/vip/` (lihat `VipLoginController::intendedVipUrl`) |
| Panel admin menampilkan halaman penyiapan 2FA | Admin belum memasang authenticator | Tombol "Mulai penyiapan" → pindai QR → masukkan kode 6 digit → simpan kode pemulihan |
| Metabase tidak bisa konek DB backbone | host/port/firewall/SSL | `psql -h <host> -U admin_full -d backbone_client` dari container; cek SSL |
| Dashboard kosong / "table not found" | GRANT belum ada untuk `admin_full` | jalankan ulang GRANT di `database/sql/admin_full_role.sql` |
| Token embed ditolak | secret tidak sama / exp salah / TTL lewat | samakan secret, cek `exp` epoch detik |
| Dashboard lambat | kartu query langsung ke `dbo` | pindahkan sumber kartu ke `metrics.*`, aktifkan cache |
| Metadata hilang setelah restart | volume tidak persisten | cek `volumes: postgres_data` di compose |
