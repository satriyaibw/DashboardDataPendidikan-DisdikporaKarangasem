# Runbook Operasional — Dashboard Pendidikan Karangasem

> **Fase 6 — Operasional & Pemeliharaan** (`MasterPlan.md` §10, Issue #11)
> Terakhir diperbarui: 2026-10-10

Dokumen ini adalah deliverable Definition of Done Fase 6. Setiap langkah
procedur di bawah **sudah dicoba** di lingkungan pengembangan dan hasilnya
dicatat apa adanya.

---

## 1. Arsitektur ringkas

```
Internet → Nginx (:443) ┬→ Laravel 13 (app + Filament /admin)
                        │        └── app_db (Postgres) ← user/panel/audit
                        └→ Metabase (Docker, :3000, internal/VPN)
                                 ├── metabase_postgres (metadata: dashboard, kartu)
                                 └── backbone_client (Postgres, read-only "analis")
```

Aturan yang tidak boleh dilanggar:

| Aturan | Kenapa |
|---|---|
| Laravel **tidak pernah** query `backbone_client` | Pemisahan aplikasi dari sumber data Pusdatin |
| Hanya Metabase yang menyentuh `backbone_client`, dengan role read-only | Mencegah aplikasi salah tulis ke data pendidikan |
| `app_db` **tidak** di-backup bersama `backbone_client` | Backbone milik Pusdatin, sumber kebenaran eksternal |

Aplikasi menolak akses secara **hanya menolak** (middleware `CheckVipAccess`).
Penonaktifan massal dilakukan job terjadwal — bukan saat request masuk.

---

## 2. Pintu masuk operasional

| Yang | Lokasi |
|---|---|
| Env aplikasi | `dashboard-karangasem/.env` (contoh: `.env.example`) |
| Env Metabase | `deploy/metabase/.env` |
| Log aplikasi | `dashboard-karangasem/storage/logs/laravel.log` |
| Log backup | `dashboard-karangasem/storage/logs/backup.log` |
| Dump backup | `dashboard-karangasem/storage/app/backups/{daily,weekly}/` |
| Laporan PII | `dashboard-karangasem/storage/app/security/` |
| Contoh cron | `deploy/cron/dashboard-schedule.cron`, `deploy/cron/dashboard-backup.cron` |
| Nginx (contoh) | `deploy/nginx/dashboard.conf`, `deploy/nginx/metabase.conf` |

Menjalankan di lingkungan lokal:

```bash
cd dashboard-karangasem
cp .env.example .env          # isi ADMIN_PASSWORD & METABASE_*
vendor/bin/sail up -d
vendor/bin/sail artisan migrate --seed
```

---

## 3. Restart

| Komponen | Perintah | Catatan |
|---|---|---|
| Aplikasi (dev) | `vendor/bin/sail restart app` | Database tidak ikut restart |
| Aplikasi (dev, semua) | `vendor/bin/sail restart` | |
| Postgres aplikasi | `vendor/bin/sail restart pgsql` | Hentikan dulu agar tidak menulis setengah jalan |
| Metabase + DB | `docker compose -f deploy/metabase/docker-compose.yml restart` | `metabase` melakukan migrasi saat start pertama — tunggu 1–3 menit |
| Nginx | `sudo systemctl reload nginx` | `reload`, bukan `restart`, agar koneksi tidak putus |

Setelah restart aplikasi, jalankan:

```bash
cd dashboard-karangasem
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/up   # 200 = sehat
```

Bila `php artisan config:cache` aktif dan `.env` diubah, **wajib** jalankan
`vendor/bin/sail artisan config:clear` — konfigurasi lama masih tersimpan di
cache dan perubahan `.env` tidak akan terlihat.

---

## 4. Refresh cache Metabase

Cache query Metabase disimpan di database metadata-nya. Refresh diperlukan
setiap kali **data sumber berubah** (semester baru, koreksi Pusdatin).

1. Buka UI admin Metabase: `http://localhost:3000/admin/databases`
2. Pilih koneksi `backbone_admin`
3. Klik **Re-sync model cache** (untuk perubahan skema)
4. Untuk cache hasil kueri: buka dashboard → ikon lambang jam → **Refresh cache**

Menghapus cache dari CLI (mematikan proses Metabase sebentar):

```bash
docker compose -f deploy/metabase/docker-compose.yml restart metabase
```

---

## 5. Rotasi `METABASE_EMBEDDING_SECRET`

> ### ⚠️ JANGAN pernah menyentuh `MB_ENCRYPTION_SECRET_KEY`
>
> `MB_ENCRYPTION_SECRET_KEY` mengenkripsi kredensial koneksi database yang
> disimpan di metadata Metabase. Mengubahnya membuat **semua kredensial
> koneksi tidak dapat dibaca** dan seluruh dashboard mati sampai tiap koneksi
> di-set ulang satu per satu.
>
> Yang boleh dirotasi hanyalah `MB_EMBEDDING_SECRET_KEY` — secret untuk
> menandatangani token embed. Lihat `docs/metabase-ids.md`.

Langkah rotasi embedding secret:

```bash
# 1. Generate secret baru (min. 32 karakter)
openssl rand -hex 32

# 2. Update deploy/metabase/.env
#    METABASE_MB_EMBEDDING_SECRET_KEY=<secret-baru>

# 3. Restart Metabase
docker compose -f deploy/metabase/docker-compose.yml restart metabase

# 4. Update dashboard-karangasem/.env — HARUS identik
#    METABASE_EMBEDDING_SECRET=<secret-baru>

# 5. Bersihkan cache konfigurasi Laravel & verifikasi
cd dashboard-karangasem
vendor/bin/sail artisan config:clear
vendor/bin/sail artisan metabase:check-embedding   # wajib keluar 0
```

Bila `metabase:check-embedding` keluar non-nol dengan status 401/403, secret
belum identik di kedua sisi.

---

## 6. Tambah / perpanjang user VIP

> **Selalu lewat panel Filament, jangan lewat SQL.**
> Penulisan langsung ke tabel `users` melewati observer, sehingga jejak audit
> tidak tercatat dan akun bisa terlihat aktif tanpa riwayat.

1. Buka `https://<domain>/admin` (login + kode TOTP)
2. **Users → New user**
3. Isi nama, email, password (minimal 12 karakter, huruf besar/kecil, angka)
4. Pilih role **VIP** — **jangan** `admin`
5. Status **Aktif**
6. Masa berlaku: kosongkan `expires_at` untuk langganan **tanpa batas waktu**
7. **Create**

Perpanjang 30 hari atau aktifkan/nonaktifkan lewat **Actions** pada baris user.
Setiap perubahan tercatat di tabel `admin_activity_logs`.

Menonaktifkan manual (mis. pelanggan berhenti):
**Actions → Nonaktifkan**. Akun dengan `expires_at` lampau otomatis dinonaktifkan
setiap malam pukul 00:30 WITA oleh `vip:deactivate-expired`.

---

## 7. Backup & restore

Detail lengkap: `deploy/backup/README.md`.

### Jalankan manual

```bash
./deploy/backup/run-backup.sh
./deploy/backup/restore-drill.sh
```

### Verify

```bash
tail -n 20 dashboard-karangasem/storage/logs/backup.log
ls -lh dashboard-karangasem/storage/app/backups/daily/
```

### Hasil restore drill — 2026-10-10

```
2026-10-10 13:26:37+0800 [INFO] === Restore drill dimulai ===
2026-10-10 13:26:37+0800 [INFO] Sumber: .../daily/app_db-2026-10-10.dump (44K), container: dashboard-karangasem-pgsql-1.
2026-10-10 13:26:37+0800 [INFO] Database sementara: restore_drill_20261010132637_26302.
2026-10-10 13:26:38+0800 [INFO] Restore drill BERHASIL: 15 tabel pada schema public, 1.
2026-10-10 13:26:38+0800 [INFO] === Restore drill selesai ===
2026-10-10 13:26:38+0800 [INFO] Membuang database sementara 'restore_drill_20261010132637_26302'.
```

Bukti rotasi retensi (22 file harian + 8 mingguan → 2 harian + 4 mingguan):

```
[INFO] Rotasi *.dump: menghapus app_db-2026-09-01.dump (melebihi 14 hari).
[INFO] Rotasi *.dump: menghapus app_db-2026-08-01.dump (kelebih 4 file).
```

### Memulihkan ke produksi (darurat)

> Restore drill TIDAK pernah menyentuh database yang sedang jalan. Pemulihan ke
> produksi harus dilakukan manual dan berurutan.

```bash
# 1. STOP aplikasi lebih dulu agar tidak menulis saat restore
docker compose -f deploy/nginx/../docker-compose.yml stop   # atau systemctl

# 2. Pastikan database kosong (opsional: pg_restore menimpanya)
docker exec <container-pgsql> dropdb -U <superuser> --force app_db
docker exec <container-pgsql> createdb -U <superuser> app_db

# 3. Restore
docker exec -i <container-pgsql> sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore -U "$POSTGRES_USER" --exit-on-error --dbname=app_db' \
  < dashboard-karangasem/storage/app/backups/daily/app_db-2026-10-10.dump

# 4. Nyalakan lagi & verifikasi
cd dashboard-karangasem
vendor/bin/sail artisan migrate --force
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/up   # 200
```

Dump metadata Metabase dipulihkan dengan cara sama ke container `metabase_postgres`.

---

## 8. Jadwal harian

Semua jam dalam **WITA (UTC+8)**. Scheduler memakai `config('app.timezone')` —
bila `->timezone()` hilang di `bootstrap/app.php`, semua jam bergeser 8 jam.

| Waktu | Job | Perintah |
|---|---|---|
| setiap menit | Pemicu scheduler | `php artisan schedule:run` |
| **00:30** | Nonaktifkan VIP kedaluwarsa | `vip:deactivate-expired` |
| **02:00** | Backup harian | `deploy/backup/run-backup.sh` |
| **03:00** | Pangkas audit log > 365 hari | `audit:prune --days=365` |
| tiap jam | Cek embed Metabase | `metabase:check-embedding` |
| Senin 05:00 | Pemindaian PII | `security:scan-pii` |

Urutan 00:30 → 02:00 → 03:00 disengaja: backup membaca database sebelum
berubah, dan pemangkasan log tidak pernah berjalan bersamaan dengan backup yang
masih membaca tabel yang sama.

### Memasang cron

```bash
crontab -e
# salin isi deploy/cron/dashboard-schedule.cron dan dashboard-backup.cron
# ganti <path-repo> dengan path absolut repo
crontab -l            # verifikasi
```

### Verifikasi scheduler benar-benar jalan

```bash
cd dashboard-karangasem
vendor/bin/sail artisan schedule:list
```

```text
 30 0 * * * php artisan vip:deactivate-expired
  0 2 * * * ... (backup berjalan lewat crontab terpisah)
  0 3 * * * php artisan audit:prune --days=365
  0 * * * * php artisan metabase:check-embedding
  0 5 * * 1 php artisan security:scan-pii
```

Mode dev (jangan dipakai di produksi — memblokir terminal):

```bash
vendor/bin/sail artisan schedule:work
```

Bila job tercantum tetapi tidak pernah berjalan:

```bash
vendor/bin/sail artisan schedule:clear-cache   # bersihkan lock yang menggantung
docker exec dashboard-karangasem-pgsql-1 psql -U app -d app_db \
  -c "SELECT * FROM cache WHERE key LIKE '%mutex%';"   # sisa lock
```

---

## 9. Masalah umum

| Gejala | Penyebab | Aksi |
|---|---|---|
| Halaman dashboard kosong (iframe putih) | Metabase mati atau embed ditolak | `vendor/bin/sail artisan metabase:check-embedding` |
| Semua embed mati setelah rotasi secret | `METABASE_EMBEDDING_SECRET` ≠ `MB_EMBEDDING_SECRET_KEY` | Samakan keduanya, restart Metabase, `config:clear` |
| Tampilan dashboard tidak berubah | Cache query Metabase | Refresh cache, §4 |
| Akun VIP masih bisa masuk padahal masa aktif habis | Job 00:30 belum jalan | `php artisan vip:deactivate-expired`, periksa cron §8 |
| `/up` balas 500 | DB/cache/disk bermasalah | `Log::warning 'Health check gagal.'` menyebut komponennya; pesan asli hanya ada di log, bukan di respons |
| Scheduler tidak jalan sama sekali | Cron tidak terpasang | `crontab -l`, §8 |
| `onOneServer` tidak mengunci | `CACHE_STORE` diganti `array`/`file` | Kembalikan ke `database` |
| Login admin masuk loop MFA | Secret TOTP tidak tersimpan | Panel mewajibkan MFA — selesaikan di halaman setup |
| Backup berhenti tiap malam | Permission direktori / socket docker | Jalankan manual, baca `storage/logs/backup.log` |
| Panel admin 500 setelah ubah `.env` | `config:cache` masih menyimpan nilai lama | `php artisan config:clear` |

---

## 10. Insiden akses tak sah

**Urutan — jangan dilewati, jangan diurutkan ulang:**

1. **Kunci akses lebih dulu**
   - Nonaktifkan akun yang disusupi lewat `/admin` → **Actions → Nonaktifkan**.
     Perubahan tercatat di `admin_activity_logs`.
   - Bila admin yang disusupi: nonaktifkan akun itu juga, jangan hanya
     mengganti password — kredensialnya bisa sudah dicatat orang lain.

2. **Putuskan akses teknis**
   - `docker compose -f deploy/metabase/docker-compose.yml stop metabase`
   - WANNAUPUN Metabase tidak terlihat publik, UI-nya dibatasi Nginx hanya
     dari sisi jaringan — bukan lewat autentikasi.

3. **Rotasi secret** (urutan ini penting)
   ```bash
   openssl rand -hex 32          # secret baru
   # update METABASE_MB_EMBEDDING_SECRET_KEY di deploy/metabase/.env
   docker compose -f deploy/metabase/docker-compose.yml restart metabase
   # update METABASE_EMBEDDING_SECRET di dashboard-karangasem/.env (harus identik)
   vendor/bin/sail artisan config:clear
   vendor/bin/sail artisan metabase:check-embedding
   ```
   **Jangan sentuh `MB_ENCRYPTION_SECRET_KEY`** — akan mematikan seluruh
   koneksi database Metabase.

4. **Ambil bukti log** — salin sebelum rotasi (rotasi tidak menghapus log):
   ```bash
   grep -iE 'auto_deactivated|401|403|419' dashboard-karangasem/storage/logs/laravel.log
   vendor/bin/sail artisan security:scan-pii
   ```
   Setiap respons HTTP membawa header `Request-Id`; nilai itu yang dipakai
   untuk menelusuri baris log yang sama.

5. **Cek jejak audit**
   ```sql
   docker exec dashboard-karangasem-pgsql-1 psql -U app -d app_db -c \
     "SELECT created_at, action, actor_id, subject_id, ip, properties
      FROM admin_activity_logs ORDER BY created_at DESC LIMIT 50;"
   ```

6. **Backup kondisi yang dicurigai**
   ```bash
   ./deploy/backup/run-backup.sh
   ```

7. **Eskalasi** ke owner (§11) dan catat kronologi insiden.

---

## 11. Kontak & eskalasi

| Peran | Nama | Kontak | Waktu tanggap |
|---|---|---|---|
| Owner / penanggung jawab | _(belum diisi)_ | _(belum diisi)_ | — |
| Sysadmin server | _(belum diisi)_ | _(belum diisi)_ | — |
| Pusdatin (data backbone) | _(belum diisi)_ | _(belum diisi)_ | — |

> Kolom "belum diisi" harus dilengkapi owner sebelum Fase 7 (UAT).**
> Runbook tanpa kontak eskalasi tidak memenuhi tujuannya: saat insiden terjadi,
> dokumen ini hanya berguna bila orang yang bisa dihubungi ada di dalamnya.

---

## Lampiran — Perintah verifikasi cepat

```bash
cd dashboard-karangasem

# Kesehatan aplikasi
curl -s http://localhost:8000/up                      # 200 = sehat

# healthcheck Metabase (dipakai docker healthcheck)
curl -s http://localhost:3000/api/health

# Jadwal
vendor/bin/sail artisan schedule:list

# Sentinel terjadwal
vendor/bin/sail artisan metabase:check-embedding
vendor/bin/sail artisan security:scan-pii

# Log terstruktur
grep '"request_id"' dashboard-karangasem/storage/logs/laravel.log | tail

# Kualitas
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --format agent
vendor/bin/sail composer audit
```

### Keputusan Owner yang masih terbuka (Issue #11 §6)

| # | Pertanyaan | Status |
|---|---|---|
| D1 | Kanal alert (Slack / email / Uptime Kuma) | **Belum diputuskan** — sementara cukup log + sentinel exit code |
| D2 | Retensi backup 14 hari cukup? | **Belum diputuskan** — sementara 14 hari lokal |
| D3 | Target restore drill: staging atau lokal? | **Belum diputuskan** — sementara DB lokal |
| D4 | Jam `audit:prune` 03:00 WITA sudah pas? | **Belum diputuskan** — sementara 03:00 WITA |
| D5 | Healthcheck Metabase dari luar? | **Belum diputuskan** — sementara lewat `/api/health` |