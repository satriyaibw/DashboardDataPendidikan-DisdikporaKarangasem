# Backup & Restore — Fase 6

> Spesifikasi: `MasterPlan.md` §10 (Fase 6), Issue #11
> Terakhir update: 2026-10-10

Skrip ini membackup **dua hal saja**: database aplikasi `app_db` dan database
metadata Metabase.

Database backbone `backbone_client` **sengaja tidak dibackup** — itu milik
Pusdatin dan sumber kebenaran eksternal (`MasterPlan.md` §4 prinsip 1).

---

## Berkas

| Berkas | Guna |
|---|---|
| `run-backup.sh` | Orkestrasi backup harian + rotasi retensi |
| `restore-drill.sh` | Uji restore ke database sementara |
| `lib.sh` | Fungsi bersama: logging, verifikasi, retensi |

---

## Menjalankan

```bash
# Backup harian
./deploy/backup/run-backup.sh

# Uji restore (tidak menyentuh database yang sedang jalan)
./deploy/backup/restore-drill.sh

# Lihat hasil
tail -n 20 dashboard-karangasem/storage/logs/backup.log
ls -lh dashboard-karangasem/storage/app/backups/daily/
```

---

## Konfigurasi (semua opsional)

Semua nilai dibaca dari variabel lingkungan; skrip berhenti dengan pesan jelas
bila ada yang tidak bisa ditemukan — tidak pernah mengarang nama container.

| Variabel | Default | Guna |
|---|---|---|
| `APP_DB_CONTAINER` | autodeteksi lewat `docker compose` | Container Postgres aplikasi |
| `METABASE_DB_CONTAINER` | `metabase_postgres` | Container Postgres metadata Metabase |
| `BACKUP_DIR` | `dashboard-karangasem/storage/app/backups` | Lokasi penyimpanan dump |
| `BACKUP_DAILY_RETENTION_DAYS` | `14` | Umur maksimum dump harian |
| `BACKUP_WEEKLY_RETENTION_COUNT` | `4` | Jumlah arsip mingguan |
| `BACKUP_WEEKLY_DAY` | `7` (Minggu) | Hari salinan mingguan dibuat |
| `PG_SUPERUSER` | `POSTGRES_USER` dari container | Peran untuk `dropdb` saat drill |
| `BACKUP_LOG_FILE` | `dashboard-karangasem/storage/logs/backup.log` | Lokasi log |

Menentukan container database aplikasi secara manual:

```bash
docker compose -f dashboard-karangasem/compose.yaml ps -q pgsql
# contoh keluaran: 5fcf107274ae...
APP_DB_CONTAINER=5fcf107274ae... ./deploy/backup/run-backup.sh
```

---

## Yang Dijamin oleh Skrip

1. **Exit code non-nol bila ada langkah yang gagal.** `set -euo pipefail`,
   ditambah verifikasi setelah `pg_dump`.
2. **Dump diverifikasi sebelum dianggap berhasil** — dicek tidak 0 byte,
   diawali magic bytes `PGDMP`, dan bisa dibaca `pg_restore --list`.
3. **Password tidak pernah menjadi argumen baris perintah.** Kredensial
   diambil dari `POSTGRES_PASSWORD` di dalam container lewat ekspansi `sh -c`,
   sehingga tidak terlihat di tabel proses host.
4. **Direktori backup ber-mode `700`, file dump `600`** — isinya memuat email,
   hash password, dan catatan VIP.
5. **Rotasi 14 harian + 4 mingguan** dijalankan setelah dump hari ini selesai.

---

## Restore Drill

`restore-drill.sh` **tidak pernah** memulihkan ke database yang sedang
berjalan. Ia membuat database sementara berawalan `restore_drill_`, memulihkan
dump ke sana, memverifikasi jumlah tabel, lalu membuangnya.

```bash
./deploy/backup/restore-drill.sh                       # dump harian terbaru
./deploy/backup/restore-drill.sh --file=<path>         # dump tertentu
./deploy/backup/restore-drill.sh --keep                # simpan DB sementara
```

Sisa database drill dari eksekusi yang gagal otomatis dibuang pada awal
eksekusi berikutnya.

Hasil uji restore yang benar-benar dijalankan pada **2026-10-10**:

```
2026-10-10 13:26:37+0800 [INFO] === Restore drill dimulai ===
2026-10-10 13:26:37+0800 [INFO] Sumber: .../daily/app_db-2026-10-10.dump (44K), container: dashboard-karangasem-pgsql-1.
2026-10-10 13:26:37+0800 [INFO] Database sementara: restore_drill_20261010132637_26302.
2026-10-10 13:26:38+0800 [INFO] Membuang sisa drill sebelumnya: restore_drill_20261010132433_23408.
2026-10-10 13:26:38+0800 [INFO] Restore drill BERHASIL: 15 tabel pada schema public, 1.
2026-10-10 13:26:38+0800 [INFO] === Restore drill selesai ===
2026-10-10 13:26:38+0800 [INFO] Membuang database sementara 'restore_drill_20261010132637_26302'.
```

Bukti rotasi retensi (sebelum 22 file harian + 8 mingguan, sesudah 2 harian + 4 mingguan):

```
[INFO] Rotasi *.dump: menghapus app_db-2026-09-01.dump (melebihi 14 hari).
...
[INFO] Rotasi *.dump: menghapus app_db-2026-08-01.dump (kelebih 4 file).
```

> Catatan: dump `.dump` yang dipakai di atas sengaja dibersihkan setelah
> verifikasi. Backup produksi yang sesungguhnya dilakukan oleh cron — lihat
> `deploy/cron/dashboard-backup.cron`.