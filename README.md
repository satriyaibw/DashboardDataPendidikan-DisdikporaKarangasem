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
