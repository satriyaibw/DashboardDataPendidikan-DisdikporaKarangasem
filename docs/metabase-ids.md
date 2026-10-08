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
5. Verifikasi token embed baru berfungsi

## Troubleshooting

| Gejala | Kemungkinan penyebab | Aksi |
|---|---|---|
| Metabase tidak bisa konek DB backbone | host/port/firewall/SSL | `psql -h <host> -U admin_full -d backbone_client` dari container; cek SSL |
| Dashboard kosong / "table not found" | GRANT belum ada untuk `admin_full` | jalankan ulang GRANT di `database/sql/admin_full_role.sql` |
| Token embed ditolak | secret tidak sama / exp salah / TTL lewat | samakan secret, cek `exp` epoch detik |
| Dashboard lambat | kartu query langsung ke `dbo` | pindahkan sumber kartu ke `metrics.*`, aktifkan cache |
| Metadata hilang setelah restart | volume tidak persisten | cek `volumes: postgres_data` di compose |
