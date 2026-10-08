# MasterPlan — Dashboard Data Pendidikan Kabupaten Karangasem

> **Status:** FINAL — dokumen ini adalah satu-satunya sumber kebenaran (*single source of truth*) untuk eksekusi.
> **Audience:** Junior programmer / AI agent berbiaya rendah.
> **Cara pakai:** Kerjakan **fase secara berurutan**. Jangan lompat fase. Setiap fase punya *Deliverable* dan *Definition of Done (DoD)*. Jika DoD belum terpenuhi, fase belum selesai.
> **Aturan:** Jangan menebak nama tabel/kolom. Selalu verifikasi ke database nyata. Lihat [Lampiran A](#lampiran-a--peta-database-nyata).

---

## 1. Ringkasan Eksekutif

Tujuan: menyediakan **dashboard data pendidikan Kabupaten Karangasem** dengan dua tingkat akses:

- **Publik (anonim, tanpa login):** hanya dashboard agregat non-sensitif.
- **VIP (login, berbatas waktu):** dashboard analitik eksklusif, akses otomatis dicabut saat masa langganan habis.
- **Admin:** mengelola akun VIP via panel admin.

Pendekatan: **Metabase** sebagai mesin analitik + **Laravel** sebagai pembungkus (wrapper) web & kontrol akses + **PostgreSQL** (data existing Pusdatin) sebagai sumber data. Metabase di-embed ke Laravel menggunakan **Signed / Guest Embedding (JWT)**.

---

## 2. Hasil Review Draft Plan (Gap Analysis)

Draft awal benar secara arah, tetapi memiliki **gap kritis** berikut. MasterPlan ini sudah mengoreksinya.

| # | Isu di Draft | Dampak | Koreksi di MasterPlan |
|---|---|---|---|
| 1 | Menganggap `datamart` siap pakai | **Salah: `datamart` KOSONG (0 baris)** — terverifikasi dari dump | Database `backbone_client` **sudah punya role `analis`** (read-only, `GRANT SELECT` di semua schema). Tabel `datamart.*` **ada secara struktur tetapi belum diisi data**. Gunakan **schema kontrak `metrics`** yang dibangun dari `dbo`/`ref`, dengan jalur migrasi ke `datamart` saat terisi. Lihat §5 & Lampiran A. |
| 2 | Mengira data mentah harus diagregasi sendiri | Sebagian benar | Karena `datamart` kosong, agregat sementara dibangun dari `dbo.*` + `ref.*` ke schema kontrak `metrics`. Dashboard **hanya** menempel ke `metrics.*`. Saat `datamart` terisi, cukup ganti sumber view kontrak. |
| 3 | Aktivasi **"Public Embedding" + "Signed Embedding"** untuk VIP | Ambigu & berisiko | Ini dua hal berbeda. **Publik (tanpa token)** → hanya untuk konten non-sensitif. **VIP → Static/Guest Embedding dengan JWT bertanda tangan**. Jangan pernah menaruh data VIP di public embed. Lihat §7. |
| 4 | Menyebut `firebase/php-jwt` untuk **"enkripsi token"** | Terminologi salah | JWT **ditandatangani (signed)**, bukan dienkripsi. Algoritma **HS256** dengan *Embedding Secret Key* Metabase. Lihat §7. |
| 5 | Middleware mengubah `is_active=false` saat request | *Side-effect* pada operasi baca; rawan race | Middleware **hanya menolak akses**. Penonaktifan massal dilakukan **scheduler terjadwal**. |
| 6 | Scheduler di `app/Console/Kernel.php` | **File sudah tidak ada** di Laravel 11+ | Gunakan `routes/console.php` atau `bootstrap/app.php` → `->withSchedule(...)`. Lihat §8.6. |
| 7 | Enum `role` **DAN** Spatie Permission bersamaan | Duplikasi & sumber bug | Pilih **satu**: Spatie roles sebagai otoritas, atau enum sederhana + Gate. Rekomendasi: **Spatie roles**. Lihat §6.4. |
| 8 | Role `umum` disimpan di tabel `users` | Tidak perlu | Pengunjung publik = **guest (anonim)**, tidak ada baris user. Role hanya `admin` dan `vip`. |
| 9 | Laravel disiratkan mengakses DB backbone langsung | Risiko keamanan | Laravel **hanya** mengakses DB aplikasinya sendiri. Sumber data backbone hanya diakses **Metabase** via `analis`. Pemisahan tegas. Lihat §4. |
| 10 | Tidak menyebut **PII** | Risiko hukum/privasi | `dbo.peserta_didik` (nama, NISN, **NIK**), `dbo.ats` (**NIK, No. KK**), `dbo.ptk` (NIK, NUPTK) **adalah PII**. Dashboard tidak boleh menampilkan PII mentah. Lihat §9. |
| 11 | Timezone tidak disebut | Bug pada `expires_at` | Set `APP_TIMEZONE=Asia/Makassar` (WITA). Lihat §6.2. |
| 12 | Versi tidak dipatok | Minus reprodusibilitas | Patok versi di §3. |
| 13 | Tidak ada backup, observability, monitoring | Operasional rapuh | Fase 6 mencakup backup, healthcheck, log. |

---

## 3. Keputusan Teknis (Final, Dipatok)

| Layer | Teknologi | Versi | Catatan |
|---|---|---|---|
| Data sumber | PostgreSQL (existing Pusdatin) | 16.x | DB `backbone_client`, role `analis` sudah ada |
| Analytics | Metabase OSS (Docker) | rilis stabil terbaru | Punya DB metadata sendiri (Postgres terpisah) |
| Web wrapper | Laravel | **13.x** | Butuh PHP 8.3–8.5 |
| Admin panel | Filament | **5.x** | Butuh Laravel ≥ 11.28, Livewire 4, Tailwind 4 |
| RBAC | spatie/laravel-permission | rilis stabil kompatibel Laravel 13 | Pin versi saat install |
| JWT | firebase/php-jwt | ^6 | HS256 |
| Runtime PHP | PHP-FPM | 8.3 / 8.4 | cURL, PDO, mbstring, openssl, xml wajib |
| Web server | Nginx | stabil | HTTPS wajib |
| Container | Docker + Docker Compose | stabil | Untuk Metabase (+ DB metadata-nya) |

> **Aturan versi:** jalankan `composer require <paket> --dry-run` dulu; jika bentrok dengan Laravel 13, turunkan Laravel ke versi LTS terdekat **atau** pin versi paket yang kompatibel. Catat versi final di `composer.lock` dan commit.

---

## 4. Arsitektur Target

```
                        Internet
                           │
                    HTTPS (443)
                           │
                 ┌─────────▼──────────┐
                 │   Nginx (reverse)  │
                 └────┬───────────┬───┘
                      │           │
        dash.example.id│           │metabase.example.id (internal/VPN)
                      │           │
              ┌───────▼──────┐   ┌▼────────────────────┐
              │  Laravel 13  │   │   Metabase (Docker) │
              │  + Filament  │   │   - Public embed    │
              │              │   │   - Guest JWT embed │
              │  users.db ────┼──┐│   - Query cache     │
              └──────┬───────┘  │└────────┬────────────┘
                     │          │         │ (role analis, SELECT only)
        IFRAME /embed │          │         │
                     │          │  ┌──────▼─────────────────┐
                     │          │  │  PostgreSQL backbone_   │
                     │          │  │  client  (metrics,     │
                     │          │  │  datamart, dbo, ref)   │
                     │          │  └─────────────────────────┘
                     │          │
              ┌──────▼───────┐  │
              │ PostgreSQL   │◀─┘
              │  app_db      │  (HANYA data user/sesi Laravel)
              │  (users,     │
              │  sessions)   │
              └──────────────┘
```

**Prinsip:**
1. Laravel **tidak pernah** query `backbone_client`. Hanya Metabase yang boleh.
2. Metabase memakai role `analis` (read-only). Tidak boleh DDL/DML.
3. Secret/credential hanya lewat **environment variable**, tidak pernah masuk git.

---

## 5. Fase 1 — Data Layer (Database)

**Tujuan:** menyediakan sumber data agregat yang siap, aman, dan **stabil menghadapi perubahan sumber**.

> **Konteks penting:** schema `datamart` **ada secara struktur tetapi KOSONG (0 baris)** pada dump saat ini. Diperkirakan akan terisi oleh job penarikan backbone Pusdatin pada jadwal berikutnya. Karena itu Fase 1 **tidak** boleh menempel langsung ke `datamart`; gunakan **lapisan kontrak `metrics`**.

### 5.1 Verifikasi aset existing
- [ ] Restore/inspeksi dump `backbone_client_*.dump` di lingkungan kerja.
- [ ] Konfirmasi keberadaan schema: `datamart`, `dbo`, `ref`, `qc`, `sync`.
- [ ] Konfirmasi role `analis` ada dan hanya punya `SELECT` (+ `USAGE` schema, `CONNECT`).
- [ ] **Konfirmasi `datamart.*` kosong (0 baris)** pada dump saat ini.
- [ ] Konfirmasi `dbo.*` berisi data (sumber agregasi sementara), mis. `dbo.peserta_didik`, `dbo.ptk`, `dbo.rombongan_belajar`.
- [ ] Dokumentasikan tabel & maknanya ke `docs/data-dictionary.md` (Lampiran A sebagai titik awal).

### 5.2 Validasi akses read-only
- [ ] Uji login sebagai `analis`, jalankan `SELECT` (harus sukses) dan `INSERT/UPDATE/DELETE/DDL` (harus **gagal**).
- [ ] Jika `analis` belum ada di server produksi → minta Pusdatin membuatkannya dengan pola yang sama (jangan pakai superuser).

### 5.3 Lapisan kontrak `metrics` (WAJIB)
- [ ] Buat schema `metrics` berisi view/view materialized dengan **kontrak kolom tetap** (bentuk *long*): mis. `semester_id, kode_kecamatan, kecamatan, jenjang, jenis_kelamin, jumlah`.
- [ ] Bangun view kontrak **dari `dbo.*` + `ref.*`** (karena `datamart` masih kosong).
- [ ] Beri `analis` `SELECT` pada schema `metrics`.
- [ ] **Metabase HANYA mereferensikan `metrics.*`** — bukan `dbo`/`datamart` langsung. Ini yang membuat dashboard tahan terhadap perubahan sumber data.
- [ ] Simpan DDL view kontrak di repo: `database/sql/metrics_*.sql`.

### 5.4 Jalur migrasi ke `datamart` (saat terisi)
- [ ] Saat job Pusdatin mengisi `datamart`, ubah isi view kontrak menjadi `CREATE OR REPLACE VIEW metrics.v_... AS SELECT ... FROM datamart....`
- [ ] Bentuk `datamart.*` adalah **wide** (kolom `_l`/`_p` per kategori, mis. `pd_tkt_1_l`, `ptk_guru_s1_p`) → lakukan **unpivot** ke bentuk *long* yang sudah ditetapkan kontrak.
- [ ] **Nama & tipe kolom kontrak tidak boleh berubah** agar dashboard Metabase tidak perlu diutak-atik.
- [ ] Tambahkan **test kesetaraan angka** (view dari `dbo` vs view dari `datamart`) sebelum cutover.

### 5.5 Materialized View (kondisional)
- [ ] Bila ada view kontrak yang berat: jadikan **Materialized View** + **UNIQUE INDEX** agar bisa `REFRESH MATERIALIZED VIEW CONCURRENTLY` (tanpa memblokir pembaca).
- [ ] Simpan DDL MV di repo: `database/sql/mv_*.sql`.

### 5.6 Refresh otomatis (kondisional)
- [ ] Buat skrip `refresh_mviews.sql` dan jadwalkan **setelah** proses sinkronisasi backbone Pusdatin selesai (koordinasi dengan Pusdatin; kemungkinan `pg_cron` atau cron OS).
- [ ] Pastikan `REFRESH ... CONCURRENTLY` untuk MV yang dilayani ke pengguna.

**Deliverable Fase 1:** `docs/data-dictionary.md`, schema `metrics` + DDL view kontrak, hasil uji read-only, (opsional) DDL MV + skrip refresh.
**DoD:** `analis` login, bisa baca `metrics`, tidak bisa tulis; view kontrak berisi angka agregat yang benar; data dictionary lengkap; prosedur migrasi ke `datamart` terdokumentasi.

---

## 6. Fase 2 — Metabase

**Tujuan:** Metabase berjalan, terhubung read-only, dashboard publik & VIP tersedia serta ter-cache.

### 6.1 Deploy
- [ ] Jalankan Metabase OSS + PostgreSQL metadata-nya via Docker Compose (`deploy/metabase/docker-compose.yml`).
- [ ] Set `MB_EMBEDDING_SECRET_KEY` (min. 32 char acak) via env, **jangan** hardcode.
- [ ] Persist volume: DB metadata Metabase, plugin, upload.
- [ ] Akses Metabase hanya dari jaringan internal/VPN (bind ke localhost/reverse proxy), kecuali endpoint embed.

### 6.2 Koneksi data
- [ ] Tambah **1 koneksi database** tipe PostgreSQL:
  - **Koneksi (`backbone_admin`)**: host backbone, DB `backbone_client`, user `admin_full`, **SSL aktif**, query native **YA**. Untuk semua dashboard (Publik, VIP, Admin).
- [ ] Arahkan Metabase ke semua schema (`dbo`, `ref`, `datamart`, `metrics`) — admin bisa query native ke semua schema.
- [ ] Uji koneksi & query sample.

### 6.3 Dashboard
- [ ] Susun **Dashboard Publik** (`PUBLIC_DASHBOARD_ID`): hanya agregat non-sensitif (mis. jumlah sekolah, jumlah siswa per kecamatan, rasio guru/murid). Tanpa PII.
- [ ] Susun **Dashboard VIP** (`VIP_DASHBOARD_ID`): analitik lebih dalam (usia, golongan, pendidikan, rombel, kelulusan, dll) + akses PII. User VIP **tidak bisa query native**, hanya bisa lihat/filter/download grafik.
- [ ] Susun **Dashboard Admin** (`ADMIN_DASHBOARD_ID`): full view, query native ke `dbo.*` untuk verifikasi/troubleshooting. Read-only (no DELETE/EDIT).
- [ ] Catat ID dashboard + ID pertanyaan penting → `docs/metabase-ids.md`.

### 6.4 Cache & performa
- [ ] Aktifkan **query caching** di Admin Metabase.
- [ ] Untuk kartu berat: aktifkan cache/refresh terjadwal.
- [ ] Pastikan dashboard memakai view kontrak `metrics.*` (bukan query berat langsung ke `dbo`).

### 6.5 Embedding settings
- [ ] Aktifkan **Static embedding / Guest embedding** (Signed JWT) untuk dashboard VIP.
- [ ] Untuk publik: gunakan **public link/embed** tanpa token hanya untuk Dashboard Publik.
- [ ] Salin *Embedding Secret Key* ke env Laravel (`METABASE_EMBEDDING_SECRET`).
- [ ] Catat *Site URL* Metabase (`METABASE_SITE_URL`).

### 6.6 Role & Akses (3 Role)
- [ ] **Admin** (`admin_full`): full view, query native ke `dbo.*`, read-only (no DELETE/EDIT). Bisa lihat semua data termasuk PII.
- [ ] **VIP**: bisa lihat PII melalui dashboard VIP yang disiapkan admin. **Tidak bisa query native**, hanya bisa lihat/filter/download grafik.
- [ ] **Public**: hanya agregat, tanpa PII, tanpa data detail. Hanya bisa melihat dashboard Publik yang disiapkan admin.

**Kontrol akses dilakukan melalui permission per dashboard/collection, bukan per koneksi database.**

**Deliverable Fase 2:** Metabase jalan, 2 dashboard, env secret, `docs/metabase-ids.md`.
**DoD:** keduanya dapat dibuka; VIP dashboard berhasil ditoken (uji cepat dengan skrip); caching aktif.

---

## 7. Fase 3 — Laravel + Filament (Web Wrapper & Admin)

**Tujuan:** kerangka web, autentikasi, RBAC, dan panel admin siap.

### 7.1 Inisialisasi
- [ ] `laravel new dashboard-karangasem` (Laravel 13) atau `create-project`.
- [ ] Set `.env`: `APP_TIMEZONE=Asia/Makassar`, `APP_URL=https://dash.example.id`, kredensial DB app.
- [ ] **DB aplikasi terpisah** (mis. `dash_app`). Jangan arahkan ke `backbone_client`.
- [ ] Install Filament 5: `composer require filament/filament:"^5.0"` lalu `php artisan filament:install --panels`.
- [ ] Install Spatie Permission: `composer require spatie/laravel-permission` + publish + migrate.
- [ ] Health endpoint: `GET /up`.

### 7.2 Skema user
- [ ] Migrasi tambahan pada `users`:
  - `expires_at` (datetime, nullable, **index**)
  - `is_active` (boolean, default true)
  - `vip_notes` / `label` (nullable, untuk catatan admin)
- [ ] **Jangan** pakai enum `role` **dan** Spatie sekaligus. Pilih Spatie:
  - Role: `admin`, `vip`. (`umum` = guest, tanpa baris user.)
  - Seeder: buat role + 1 akun admin awal.

### 7.3 Auth
- [ ] Filament panel `/admin` dibatasi role `admin` (gate via `FilamentUser::canAccessPanel`).
- [ ] Halaman login VIP terpisah (mis. pakai Breeze atau route login sederhana) → redirect ke `/vip/dashboard`.
- [ ] Login admin via Filament.

### 7.4 Resource Filament
- [ ] `UserResource`: CRUD user VIP; field `name`, `email`, `password`, `expires_at` (DateTimePicker), `is_active`, roles.
- [ ] Kolom tabel: status aktif/kedaluwarsa (badge), `expires_at`.
- [ ] Action cepat: perpanjang masa aktif, aktif/nonaktifkan.

### 7.5 Middleware `CheckVipAccess`
- [ ] Terapkan pada grup rute `/vip/*`.
- [ ] Logika: user login? → punya role `vip`? → `is_active` true? → `expires_at` null atau > now?
- [ ] Jika gagal: **logout + redirect ke login** dengan pesan. **Tidak** mengubah DB.
- [ ] Tambahkan pengecekan saat token embed hendak dibuat (defense in depth).

**Deliverable Fase 3:** repo Laravel berjalan, admin bisa login, admin bisa buat user VIP, middleware aktif.
**DoD:** admin buat VIP → VIP login sukses; VIP kedaluwarsa → ditolak.

---

## 8. Fase 4 — Integrasi Signed Embedding

**Tujuan:** halaman publik & VIP menampilkan dashboard Metabase dengan aman.

### 8.1 Konfigurasi
- [ ] `composer require firebase/php-jwt:^6`.
- [ ] Tambah `config/metabase.php` + `.env`:
  - `METABASE_SITE_URL`
  - `METABASE_EMBEDDING_SECRET`
  - `METABASE_PUBLIC_DASHBOARD_ID`
  - `METABASE_VIP_DASHBOARD_ID`
  - `METABASE_EMBED_TTL` (default 600 detik / 10 menit)

### 8.2 Service `MetabaseEmbedService`
- [ ] Method `publicUrl(): string` → URL public embed (`/public/dashboard/<id>`).
- [ ] Method `signedUrl(array $params = []): string`:
  - Payload: `{ "resource": { "dashboard": <id> }, "params": {...}, "exp": now+ttl }`
  - Sign **HS256** dengan `METABASE_EMBEDDING_SECRET`.
  - URL: `{SITE_URL}/embed/dashboard/{token}#bordered=true&titled=true`
- [ ] **Jangan** log token. **Jangan** kirim secret ke frontend.

### 8.3 Routes & Controller
- [ ] `GET /` → halaman publik (jika ada) memuat iframe public embed.
- [ ] `GET /vip/dashboard` → `auth` + `check.vip` + `role:vip` → hitung `signedUrl()` → kirim ke Blade.
- [ ] Blade: `<iframe src="{{ $embedUrl }}" ...>` dengan `loading="lazy"`, `referrerpolicy`, dan `title` aksesibel.

### 8.4 Keamanan embed
- [ ] `Content-Security-Policy: frame-src {METABASE_SITE_URL}`.
- [ ] Metabase: hanya endpoint `/embed/dashboard/*` yang boleh di-iframe; aktifkan `X-Frame-Options: SAMEORIGIN`/`ALLOW-FROM` seperlunya.
- [ ] Token TTL pendek (±10 menit) + halaman VIP memuat ulang token bila perlu (endpoint `GET /vip/embed-url` JSON untuk refresh tanpa reload penuh).

### 8.5 Uji token
- [ ] Unit test payload & signature.
- [ ] Uji negatif: token kedaluwarsa / secret salah → Metabase menolak.

**Deliverable Fase 4:** halaman publik & VIP tampil; unit test embed lulus.
**DoD:** VIP aktif melihat dashboard; token tidak pernah bocor ke klien/log.

---

## 9. Fase 5 — Privasi, Keamanan & Hardening

- [ ] **PII:** pastikan tidak ada dashboard/kartu menampilkan `nama`, `nisn`, `nik`, `no_kk`, `nuptk`, alamat, atau identitas individu. Hanya agregat.
- [ ] Batasi akses Metabase UI ke VPN/admin; expose hanya embed.
- [ ] Rate limit login (`RateLimiter`) + captcha bila perlu.
- [ ] Password: hash default Laravel; kebijakan minimal via Filament.
- [ ] HTTPS penuh; HSTS; cookie `Secure`, `HttpOnly`, `SameSite=Lax`.
- [ ] Rotasi `METABASE_EMBEDDING_SECRET` prosedur didokumentasikan.
- [ ] Audit log admin (aktivitas buat/perpanjang/nonaktifkan user VIP).
- [ ] Dependency scan (`composer audit`).

**DoD:** checklist keamanan lulus; tidak ada PII terekspos; rotasi secret terdokumentasi.

---

## 10. Fase 6 — Operasional & Pemeliharaan

### 10.1 Penjadwalan (Laravel 13)
- [ ] **Catatan:** `app/Console/Kernel.php` **tidak ada** di Laravel 11+. Daftarkan di `routes/console.php` / `bootstrap/app.php` `withSchedule()`.
- [ ] Perintah artisan `vip:deactivate-expired`:
  - Cari user role `vip` dengan `expires_at < now()` dan `is_active=true` → set `is_active=false` + catat log.
- [ ] Jadwalkan harian (`dailyAt('00:30')`) dengan `withoutOverlapping()` + `onOneServer()`.

### 10.2 Backup
- [ ] Backup harian DB aplikasi + DB metadata Metabase.
- [ ] Backup/restore drill didokumentasikan (`docs/runbook.md`).

### 10.3 Observability
- [ ] Log channel terstruktur; alert pada error 5xx & job gagal.
- [ ] Healthcheck `/up`, Metabase healthcheck, disk/DB monitoring.

### 10.4 Runbook
- [ ] `docs/runbook.md`: restart, refresh cache Metabase, rotasi secret, tambah VIP, restore backup, insiden akses.

**DoD:** scheduler berjalan & teruji; backup + runbook ada.

---

## 11. Fase 7 — QA & UAT (dijalankan lintas fase)

### 11.1 Skenario wajib
| ID | Skenario | Ekspektasi |
|---|---|---|
| T1 | Guest buka `/` | Dashboard publik tampil tanpa login |
| T2 | Guest buka `/vip/dashboard` | Redirect ke login |
| T3 | VIP aktif login | Dashboard VIP tampil |
| T4 | VIP `expires_at` di masa lalu | Ditolak + pesan; otomatis nonaktif setelah scheduler |
| T5 | VIP `is_active=false` | Ditolak |
| T6 | Token embed kedaluwarsa | Metabase menolak |
| T7 | Admin buat/perpanjang user | Berhasil + tercatat |
| T8 | User non-admin akses `/admin` | 403 |
| T9 | Query `analis` write | Gagal |
| T10 | Dashboard besar berulang | Kena cache, cepat |

### 11.2 Otomatisasi
- [ ] Feature test Laravel untuk T2–T8.
- [ ] Unit test service embed.
- [ ] Panduan UAT manual (`docs/uat.md`) untuk T1, T9, T10.

**DoD:** semua skenario lulus di staging.

---

## 12. Struktur Repo yang Diharapkan

```
dashboard-karangasem/
├─ app/
│  ├─ Http/Controllers/MetabaseController.php
│  ├─ Http/Middleware/CheckVipAccess.php
│  ├─ Services/MetabaseEmbedService.php
│  ├─ Console/Commands/DeactivateExpiredVips.php
│  └─ Filament/Resources/UserResource.php
├─ config/metabase.php
├─ database/migrations/...
├─ database/sql/metrics_*.sql       (view kontrak — WAJIB)
├─ database/sql/mv_*.sql            (opsional, bila pakai Materialized View)
├─ deploy/metabase/docker-compose.yml
├─ docs/
│  ├─ data-dictionary.md
│  ├─ metabase-ids.md
│  ├─ runbook.md
│  └─ uat.md
├─ routes/web.php, routes/console.php
├─ tests/
└─ .env.example
```

---

## 13. Konvensi untuk Eksekutor (Junior/Agent)

1. **Satu fase = satu PR/commit besar** dengan pesan jelas.
2. Selalu update `.env.example` untuk setiap env baru.
3. Jangan hardcode ID dashboard/token/secret → selalu config.
4. Tambah test untuk logika akses & embed.
5. Jika spec ambigu → **berhenti dan tanya**, jangan menebak.
6. Verifikasi nama tabel/kolom ke database nyata sebelum query.
7. Jaga agar Laravel tidak pernah menyentuh DB backbone.

---

## 14. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| `analis`/schema tidak ada di produksi, atau `datamart` tetap kosong | Fase 1 gagal / agregat tidak ada | Verifikasi lebih awal; bangun `metrics` dari `dbo/ref`; koordinasi Pusdatin untuk jadwal pengisian `datamart` |
| Versi Filament/Laravel bentrok | Blokir | `--dry-run`, pin versi, siapkan fallback LTS |
| PII bocor di dashboard | Hukum/privasi | Review kartu, larang kolom PII, review berkala |
| Secret embed bocor | Akses tak sah | Env-only, rotasi, TTL pendek |
| Token embed di-log | Kebocoran | Larang logging token, review logging |
| Scheduler ganda | Race | `onOneServer()` + `withoutOverlapping()` |
| Jam server salah | `expires_at` bug | NTP + timezone `Asia/Makassar` |
| Data backbone belum refresh | Data basi | Cache invalidation terjadwal + notifikasi |

---

## 15. Checklist Global (ringkas)

- [ ] F1 Lapisan kontrak `metrics` dibangun dari `dbo/ref`; jalur migrasi ke `datamart` disiapkan
- [ ] F2 Metabase + 2 dashboard + cache + secret
- [ ] F3 Laravel 13 + Filament 5 + RBAC + middleware
- [ ] F4 Signed embedding publik & VIP
- [ ] F5 Privasi/keamanan/hardening
- [ ] F6 Scheduler, backup, observability, runbook
- [ ] F7 QA/UAT semua skenario lulus

---

## Lampiran A — Peta Database Nyata

**Database:** `backbone_client` (PostgreSQL 16.x). **Owner:** `backbone`. **Role read-only:** `analis` (SELECT + USAGE schema + CONNECT).

### Skema & isi
| Skema | Isi | Dipakai dashboard? |
|---|---|---|
| `metrics` | **Lapisan kontrak buatan kita** (view/view materialized) — bentuk *long*, kolom stabil | **Ya (utama)** |
| `datamart` | **22 tabel agregat berstruktur, TAPI MASIH KOSONG (0 baris)** per sekolah/semester — belum diisi job Pusdatin | Ya (setelah terisi) |
| `dbo` | Data mentah (sekolah, peserta_didik, ptk, rombel, sarpras, akreditasi, dll) | Sumber agregat sementara |
| `ref` | Tabel referensi (agama, bentuk_pendidikan, wilayah, dll) | Ya (lookup) |
| `qc` | Validasi kualitas data | Tidak |
| `sync` | Log & checkpoint sinkronisasi backbone | Tidak |

> **Catatan:** dashboard **selalu** menempel ke `metrics.*`. Sumber `metrics` mula-mula `dbo/ref` (karena `datamart` kosong), lalu dialihkan ke `datamart` saat terisi — tanpa mengubah dashboard.

### 22 tabel `datamart` (semua ber-ciiri: `sekolah_id, semester_id, npsn, nama, bentuk_pendidikan, status_sekolah, kode_wilayah, provinsi/kabupaten/kecamatan + kolom agregat`)
- Peserta didik: `tingkat`, `usia`, `agama`, `baru_usia`, `lulus_usia`, `mengulang`, `mengulang_usia`
- PTK & Guru: `ptk_agama`, `ptk_golongan`, `ptk_pendidikan`, `ptk_usia`, `ptk_guru_agama`, `ptk_guru_golongan`, `ptk_guru_pendidikan`, `ptk_guru_masakerja`, `ptk_guru_usia`
- Kepala Sekolah: `kepsek_golongan`, `kepsek_pendidikan`, `kepsek_masakerja`, `kepsek_usia`
- Sarana & Rombel: `alat`, `rombongan_belajar`

> Contoh kolom: `pd_tkt_1_l`, `pd_tkt_1_p`, `ptk_guru_s1_l`, `rombel_1`, dst. (`_l`=laki-laki, `_p`=perempuan).

### PII (JANGAN ditampilkan)
`dbo.peserta_didik` (nama, nisn, nik), `dbo.ats` (nik, no_kk), `dbo.ptk` (nik, nuptk).

### Fakta teknis dump
- Format: `PGDMP` v1.15-0, PostgreSQL 16.15.
- 130 `PRIMARY KEY`, **0 foreign key** (integritas aplikatif, bukan constraint).
- **Tidak ada index eksplisit** dan tidak ada fungsi/trigger khusus pada dump ini → andalkan PK; tambah index hanya jika terbukti perlu.
- **`datamart` kosong (0 baris)**: seluruh `COPY datamart.*` langsung diikuti terminator `\.` (terverifikasi via `pg_restore --data-only`). Sebaliknya `dbo.*` berisi data, mis. `dbo.peserta_didik` 80.311 baris, `dbo.ptk` 8.012 baris, `dbo.rombongan_belajar` 5.077 baris, `dbo.anggota_rombel` 97.758 baris.

---

## Lampiran B — Referensi Cepat Embedding Metabase (terverifikasi)

Payload JWT (static/guest embedding), HS256:

```php
$payload = [
    'resource' => ['dashboard' => (int) config('metabase.vip_dashboard_id')],
    'params'   => (object) $params,          // gunakan objek agar JSON {} saat kosong
    'exp'      => time() + (int) config('metabase.embed_ttl'),
];
$token = JWT::encode($payload, config('metabase.embedding_secret'), 'HS256');
$url = rtrim(config('metabase.site_url'), '/')
     . '/embed/dashboard/' . $token
     . '#bordered=true&titled=true';
```

- Secret disimpan di Metabase sebagai `MB_EMBEDDING_SECRET_KEY` dan di Laravel sebagai `METABASE_EMBEDDING_SECRET` (harus sama).
- Public embed (tanpa login) **tidak** memakai token; hanya untuk Dashboard Publik non-sensitif.
- `exp` dalam detik Unix.
```