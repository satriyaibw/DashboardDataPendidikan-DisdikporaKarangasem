# Metabase Configuration — Dashboard IDs & Settings

> **Fase 2 — Metabase** (Issue #3)
> Terakhir update: 2026-10-08

## Site URL

```
METABASE_SITE_URL=http://localhost:3000
```

## Dashboard IDs

| Dashboard | ID | Akses | Keterangan |
|---|---|---|---|
| **Publik** | `2` | Public (tanpa login) | Agregat tinggi, tanpa PII |
| **VIP** | `4` | User VIP (login) | Agregat detail + PII |
| **Admin** | `3` | Admin saja | Full view, query native |

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
- **Public link/embed:** Hanya untuk Dashboard Publik
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
   tidak valid, `0` bila Metabase membalas bukan 5xx. Secret dan token **tidak
   pernah ikut tercetak** di output maupun log.
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
| Metabase tidak bisa konek DB backbone | host/port/firewall/SSL | `psql -h <host> -U admin_full -d backbone_client` dari container; cek SSL |
| Dashboard kosong / "table not found" | GRANT belum ada untuk `admin_full` | jalankan ulang GRANT di `database/sql/admin_full_role.sql` |
| Token embed ditolak | secret tidak sama / exp salah / TTL lewat | samakan secret, cek `exp` epoch detik |
| Dashboard lambat | kartu query langsung ke `dbo` | pindahkan sumber kartu ke `metrics.*`, aktifkan cache |
| Metadata hilang setelah restart | volume tidak persisten | cek `volumes: postgres_data` di compose |
