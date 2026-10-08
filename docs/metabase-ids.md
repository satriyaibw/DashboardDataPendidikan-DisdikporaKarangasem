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
| **VIP** | `4` | User VIP (login) | Agregat detail + PII (via view khusus) |
| **Admin** | `3` | Admin saja | Full view, query native |

## Database Connections

| Koneksi | ID | User | Schema | Query Native | Dipakai Untuk |
|---|---|---|---|---|---|
| `backbone_admin` | `2` | `admin_full` | `metrics`, `dbo`, `ref` | **Ya** | Admin (verifikasi, troubleshooting) |
| `backbone_read` | `3` | `analis` | `metrics` saja | **Tidak** | VIP & Public |

## Collections

| Collection | Isi | Akses |
|---|---|---|
| `Admin Only` | Dashboard Admin, Query native PII | Hanya Admin |
| `Dashboards` | Dashboard Publik, Dashboard VIP | Publik & VIP |

## Caching

- **Default TTL:** 180 menit (3 jam)
- **Cache diaktifkan:** Admin → Settings → Caching
- **Untuk kartu berat:** Set cache kustom atau scheduled refresh

## Embedding

- **Static/Guest Embedding:** Diaktifkan untuk Dashboard VIP
- **Public link/embed:** Hanya untuk Dashboard Publik
- **Embedding Secret Key:** Disimpan di `METABASE_EMBEDDING_SECRET` (Laravel) dan `MB_EMBEDDING_SECRET_KEY` (Metabase) — **harus sama**

## View PII untuk VIP

View khusus di schema `metrics` yang berisi data PII, hanya bisa diakses melalui dashboard VIP:

| View | Keterangan |
|---|---|
| `metrics.v_peserta_didik_detail` | Detail peserta didik (nama, NISN, NIK, dll) |
| `metrics.v_ptk_detail` | Detail PTK (nama, NIK, NUPTK, dll) |

## Prosedur Rotasi Secret

1. Generate secret baru: `openssl rand -hex 32`
2. Update `MB_EMBEDDING_SECRET_KEY` di `deploy/metabase/.env`
3. Update `METABASE_EMBEDDING_SECRET` di `.env` Laravel (Fase 4)
4. Restart Metabase: `docker compose -f deploy/metabase/docker-compose.yml restart`
5. Verifikasi token embed baru berfungsi

## Troubleshooting

| Gejala | Kemungkinan penyebab | Aksi |
|---|---|---|
| Metabase tidak bisa konek DB backbone | host/port/firewall/SSL | `psql -h <host> -U analis -d backbone_client` dari container; cek SSL |
| Dashboard kosong / "table not found" | GRANT `metrics` belum ada untuk `analis` | jalankan ulang GRANT di `database/sql/metrics_views.sql` |
| Token embed ditolak | secret tidak sama / exp salah / TTL lewat | samakan secret, cek `exp` epoch detik |
| Dashboard lambat | kartu query langsung ke `dbo` | pindahkan sumber kartu ke `metrics.*`, aktifkan cache |
| Metadata hilang setelah restart | volume tidak persisten | cek `volumes:` di compose |
