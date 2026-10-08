# Uji Akses Read-Only Role `analis`

Dilakukan pada instance kerja PostgreSQL 16.15, sebagai role `analis` (password via env, tidak di-commit).

| Uji | Perintah | Hasil | Status |
|---|---|---|---|
| Baca dari `metrics` | `SELECT count(*) FROM metrics.v_peserta_didik;` | 116 baris | ✅ sukses |
| Baca dari `dbo` | `SELECT count(*) FROM dbo.ptk;` | 8012 | ✅ sukses |
| Baca dari `datamart` | `SELECT count(*) FROM datamart.sekolah_alat;` | 0 | ✅ sukses |
| INSERT ke `dbo` | `INSERT INTO dbo.peserta_didik VALUES ('x');` | `ERROR: permission denied for table peserta_didik` | ✅ ditolak |
| DELETE ke `datamart` | `DELETE FROM datamart.sekolah_alat;` | `ERROR: permission denied for table sekolah_alat` | ✅ ditolak |
| CREATE TABLE di `metrics` | `CREATE TABLE metrics.t (a int);` | `ERROR: permission denied for schema metrics` | ✅ ditolak |
| DROP TABLE di `dbo` | `DROP TABLE dbo.peserta_didik;` | `ERROR: must be owner of table peserta_didik` | ✅ ditolak |
| ALTER view kontrak | `ALTER TABLE metrics.v_peserta_didik ADD COLUMN x int;` | `ERROR: must be owner of view v_peserta_didik` | ✅ ditolak |
| DELETE via view | `DELETE FROM metrics.v_peserta_didik;` | `ERROR: cannot delete from view ...` | ✅ ditolak |

## Kesimpulan (DoD §5.2)
- `analis` dapat `SELECT` dari `metrics`, `dbo`, `ref`, `datamart`, `sync`.
- `analis` **tidak dapat** menulis/DDL di semua schema. Bukan superuser.
- Di produksi, pastikan role `analis` dibuat Pusdatin dengan pola yang sama.
