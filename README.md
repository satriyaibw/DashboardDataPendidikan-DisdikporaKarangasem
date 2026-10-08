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

