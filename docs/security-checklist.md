# Checklist Keamanan — Fase 5

> **Fase 5 — Privasi, Keamanan & Hardening** (MasterPlan §9, Issue #9)
> Terakhir diperbarui: 2026-10-09

Dokumen ini adalah deliverable Definition of Done Fase 5. Setiap item punya
bukti terukur, bukan klaim.

---

## 1. Kebijakan privasi (D1)

**Keputusan owner:** akses penuh ke database backbone **dipertahankan**
(`admin_full` tetap read-only ke seluruh schema). Privasi dijaga di lapisan
**pemilihan dashboard oleh admin**: admin menyiapkan dashboard mana yang
dipublikasikan (ID → `METABASE_PUBLIC_DASHBOARD_ID`) dan mana yang khusus VIP
(`METABASE_VIP_DASHBOARD_ID`).

Implikasinya: yang harus bersih dari PII adalah dashboard yang **benar-benar
diekspos aplikasi**, yaitu Publik dan VIP. Dashboard admin internal tidak
di-embed aplikasi.

### Cara memverifikasi

```bash
cd dashboard-karangasem
# isi kredensial admin Metabase di .env (tidak di-commit)
vendor/bin/sail artisan security:scan-pii
```

Perintah akan:

1. Login ke API Metabase (`POST /api/session`) memakai
   `METABASE_ADMIN_EMAIL` + `METABASE_ADMIN_PASSWORD`. Session id hanya
   disimpan di memori variabel lokal — **tidak** dicetak, tidak ditulis ke
   log, tidak masuk laporan.
2. Membaca setiap kartu dashboard target.
   - Query **native** → teks SQL dipindai.
   - Query **MBQL** → `result_metadata` kartu dipakai lebih dulu karena
     berisi persis kolom yang tampil. Bila tidak ada, baru dipakai metadata
     **seluruh tabel** sumber (`/api/table/{id}/query_metadata`) — hasilnya
     lebih luas dari yang ditampilkan, jadi kartu agregat bisa ikut tercatat.
   - Sumber query yang bukan tabel (mis. kartu di dalam kartu) atau metadata
     yang tidak dapat dibaca akan **diperingatkan**, bukan lolos diam-diam
     sebagai "bersih". Periksa manual kartu tersebut.
3. Menulis laporan ke `storage/app/security/pii-scan-{tanggal}.json`.
   Laporan gagal ditulis → exit code non-nol, bukan lolos tanpa bukti.
4. Exit code **1** bila ada temuan pada dashboard target, **0** bila bersih.

Kolom yang diperiksa: `nik`, `nisn`, `no_kk`, `nuptk`, `nama`, `nama_lengkap`,
`alamat`, `tempat_lahir`, `tanggal_lahir`, `no_hp`, `telepon`, `email`.

### Kenapa pencocokan bukan sekadar "cari kata"

Pencarian substring akan menandai `nama_kecamatan`, `nama_sekolah`, dan
`nama_bentuk_pendidikan` — semuanya kolom agregat yang sah. `PiiScanner`
memakai batas identifier eksplisit (`(?<![A-Za-z0-9_])nama(?![A-Za-z0-9_])`)
sehingga hanya identifier utuh yang cocok; batas ditulis sebagai lookaround
alih-alih `\b` agar perilakunya tidak bergantung pada locale PCRE.
Kasus ini diuji eksplisit di
`tests/Feature/SecurityScanPiiTest.php::test_scanner_ignores_columns_that_merely_contain_a_pii_word`.

Komentar SQL dan string literal juga dibuang sebelum pencocokan. Tanpa itu,
kartu seperti `SELECT ... -- kolom nik sengaja tidak diambil` atau
`WHERE label = 'alamat'` akan dilaporkan memuat PII padahal tidak, dan laporan
yang terlalu banyak false positive akhirnya tidak dipercaya.

### Checklist manual per dashboard

Untuk **setiap** kartu, konfirmasikan:

- [ ] Sumber query menunjuk `metrics.*` (bukan `dbo.*` mentah).
- [ ] Menampilkan **agregat** (`COUNT`, `SUM`, rasio per kecamatan/jenjang),
      bukan baris per individu.
- [ ] Tidak ada kolom dari daftar PII di §1.
- [ ] Tidak ada parameter/variabel yang membuka filter per individu
      (mis. filter berdasarkan NISN).
- [ ] Unduhan (download) kartu tidak memuat kolom PII.

| Dashboard | ID | Sumber | Dikonfirmasi agregat | Tanggal |
|---|---|---|---|---|
| Publik | `2` | | | |
| VIP | `4` | | | |
| Admin (info) | `3` | | | |

### Catatan hasil eksekusi

| Tanggal | Metabase | Dashboard dipindai | Temuan | Exit |
|---|---|---|---|---|
| _diisi saat verifikasi produksi_ | v0.55.4 | publia + VIP | | |

> Metabase di lingkungan ini terikat ke `127.0.0.1:3000`
> (`deploy/metabase/docker-compose.yml`). Jalankan perintah dari host, atau
> arahkan `METABASE_SITE_URL` ke `http://host.docker.internal:3000` bila
> dijalankan dari dalam container.

---

## 2. Header keamanan

| Header | Berlaku untuk | Sumber |
|---|---|---|
| `X-Content-Type-Options: nosniff` | semua respons | `SecurityHeaders` |
| `Referrer-Policy: strict-origin-when-cross-origin` | semua respons | `SecurityHeaders` |
| `Permissions-Policy` (`camera`, `microphone`, `geolocation`, `payment`, `usb` dimatikan; `fullscreen` mengizinkan `self` + origin Metabase) | semua respons | `SecurityHeaders` |
| `Cross-Origin-Opener-Policy: same-origin` | semua respons | `SecurityHeaders` |
| `X-Frame-Options: DENY` | **hanya** respons tanpa CSP | `SecurityHeaders` |
| `Content-Security-Policy` (`frame-src`, `object-src`, `base-uri`, `frame-ancestors`) | hanya rute embed `/` dan `/vip/*` | `MetabaseCspHeaders` |
| `Strict-Transport-Security` | hanya request HTTPS **dan** `HSTS_ENABLED=true` | `StrictTransportSecurity` |

**Catatan penting:** `Permissions-Policy` memuat origin Metabase pada allowlist
`fullscreen`. Iframe dashboard bersifat cross-origin dan memakai
`allowfullscreen`; menurut spesifikasi Permissions Policy, akses fitur iframe
dipotong oleh kebijakan dokumen induk. Tanpa origin Metabase di allowlist,
tombol fullscreen kartu di dalam embed tidak berfungsi.

**CSP global sengaja tidak dipasang** — akan mematikan skrip inline
Livewire/Alpine pada panel Filament. CSP tetap per-rute seperti Fase 4.

Verifikasi:

```bash
vendor/bin/sail artisan test --compact tests/Feature/SecurityHeadersTest.php
```

---

## 3. HTTPS, HSTS & cookie

| Hal | Konfigurasi | Status produksi |
|---|---|---|
| Paksa skema https pada `route()`/`url()` | `FORCE_HTTPS=true` | |
| HSTS | `HSTS_ENABLED=true` (setelah TLS pasti jalan) | |
| `HSTS_MAX_AGE` | `31536000`; turunkan ke `0` untuk mencabut | |
| HSTS untuk seluruh subdomain | `HSTS_INCLUDE_SUBDOMAINS=false` (default); true hanya setelah semua subdomain HTTPS | |
| Cookie `HttpOnly` | `SESSION_HTTP_ONLY=true` | |
| Cookie `SameSite` | `SESSION_SAME_SITE=lax` | |
| Cookie `Secure` | `SESSION_SECURE_COOKIE=true` (setelah TLS aktif) | |
| Session terenkripsi | `SESSION_ENCRYPT=true` | |
| Proxy tepercaya | `TRUSTED_PROXIES` (wajib diisi bila di belakang Nginx) | |

**Urutan pengaktifan yang aman:** TLS dulu → `FORCE_HTTPS=true` →
`SESSION_SECURE_COOKIE=true` → **baru** `HSTS_ENABLED=true`. Mengaktifkan HSTS
sebelum TLS siap membuat domain "terkunci" HTTPS dan tidak dapat dicabut dari
sisi server.

`includeSubDomains` **tidak** ikut aktif bersama `HSTS_ENABLED`. Direktif itu
memaksa seluruh subdomain memakai HTTPS; subdomain yang belum TLS akan ditolak
sebelum sempat diakses, dan tidak ada cara melepasnya dari sisi server selain
menunggu `max-age` habis. Nyalakan `HSTS_INCLUDE_SUBDOMAINS=true` hanya setelah
seluruh subdomain benar-benar HTTPS. Saat `HSTS_MAX_AGE=0` (masa pencabutan),
direktif itu otomatis dihilangkan.

Verifikasi: `tests/Feature/SessionCookieTest.php`,
`tests/Feature/SecurityHeadersTest.php`.

---

## 4. Kata sandi & panel admin

- **Kebijakan:** minimal **12 karakter**, memuat huruf besar, huruf kecil, dan
  angka. Definisi tunggal ada di `App\Services\PasswordPolicy` (dipakai
  bersama form Filament dan seeder, tidak pernah berbeda).
- **Hashing:** memakai cast bawaan Laravel `'password' => 'hashed'`.
  **Jangan** menambahkan `Hash::make()` manual — cast-nya idempoten
  (dijaga `Hash::isHashed()`), hash ganda akan mematikan seluruh login.
- **Seeder:** `ADMIN_PASSWORD` yang tidak memenuhi kebijakan akan
  menggagalkan `db:seed` dengan pesan tersurat (fail-closed).
- **MFA:** panel admin mewajibkan multi-factor authentication TOTP
  (`isRequired: true`). Admin yang belum memasang MFA dialihkan ke halaman
  penyiapan dan tidak dapat masuk. Kode pemulihan (recovery codes) disediakan
  agar admin yang kehilangan perangkat tidak permanen terkunci.
  - Pencegahan pemakaian ulang kode menyimpan timestep kode terakhir di cache
    lalu membacanya di bawah lock. Cache store harus **berbagi antar
    proses** (`database`/`redis`/`memcached`). Store `array` memang
    menyediakan lock, tetapi isinya hanya hidup di dalam satu proses, jadi
    catatan timestep hilang antar permintaan. **Jangan** memakai
    `CACHE_STORE=array` di produksi. `CACHE_STORE=database` sudah memenuhi,
    dan `phpunit.xml` sengaja disamakan dengan produksi agar jalur ini
    benar-benar teruji.
  - **Login VIP tidak terpengaruh** — MFA hanya berlaku untuk panel `/admin`.
- **Rate limit login VIP:** 5 percobaan / 60 detik, kunci `email|ip`
  (`VipLoginController`).

**Langkah pertama untuk admin baru:** login `/admin/login` → pasang aplikasi
authenticator (TOTP) → **simpan kode pemulihan** di tempat aman.

---

## 5. Pembatasan akses UI Metabase

Metabase terikat ke `127.0.0.1:3000` sehingga tidak terekspos langsung.
Untuk produksi, gunakan reverse proxy contoh di:

- `deploy/nginx/metabase.conf` — hanya `/public/`, `/embed/`, dan `/api/health`
  yang dilayani publik; sisanya dibatasi (`allow <IP VPN/admin>; deny all;`).
- `deploy/nginx/dashboard.conf` — redirect HTTP→HTTPS ke aplikasi Laravel.

Di sisi Metabase (Admin → Embedding):

- [ ] **Allowed domains for iframes** = domain aplikasi Laravel.
- [ ] Bila domain embed berbeda dari domain Metabase, daftarkan domain pada
      **CORS** (Cross-Origin Resource Sharing).

Verifikasi dari jaringan publik:

```bash
curl -I https://metabase.example.id/admin/            # harus 403/404
curl -I https://metabase.example.id/embed/dashboard/<token-uji>  # harus dilayani
```

> Pengujian nyata membutuhkan domain + TLS produksi. Sampai itu tersedia,
> file Nginx adalah **contoh** — tidak memengaruhi runtime yang berjalan.

---

## 6. Rotasi secret

Prosedur lengkap: [`metabase-ids.md`](./metabase-ids.md#prosedur-rotasi-secret).

Ringkas:

1. `openssl rand -hex 32`
2. Update `MB_EMBEDDING_SECRET_KEY` (Metabase) **dan**
   `METABASE_EMBEDDING_SECRET` (Laravel) — harus identik.
3. Restart Metabase.
4. Verifikasi: `vendor/bin/sail artisan metabase:check-embedding`
   (exit 0 = Metabase menjawab 2xx/3xx sehingga embed dapat dilayani;
   exit 1 = konfigurasi tidak valid, atau Metabase menjawab 4xx/5xx).
   Respons 4xx — terutama 401/403 — adalah tanda paling umum bahwa embedding
   belum aktif di sisi Metabase atau secret tidak identik dengan
   `MB_EMBEDDING_SECRET_KEY`; perintah ini karena itu **gagal** pada 4xx,
   bukan menganggapnya aman.
5. Uji manual: halaman publik dan VIP tetap menampilkan iframe.

**Jangan** merotasi `MB_ENCRYPTION_SECRET_KEY` tanpa kebutuhan: kunci itu
mengenkripsi kredensial koneksi database yang tersimpan, dan merotasinya
membuat seluruh kredensial harus di-set ulang.

---

## 7. Audit log

Setiap aksi admin terhadap akun VIP tercatat di `admin_activity_logs`
(buat / perpanjang / aktif-nonaktif / ubah / hapus) dengan:

- `actor_id` — siapa admin pelaku (nullOnDelete: jejak tetap ada bila admin
  dihapus kemudian).
- `action` — mis. `user.created`, `user.updated`, `user.deleted`.
- `properties` — perubahan dalam bentuk `before`/`after`, **hanya** kolom
  dalam whitelist: `name`, `is_active`, `expires_at`, `roles`, `vip_notes`.
- `ip`, `user_agent`, `created_at`.

**Format waktu:** nilai tanggal/waktu disimpan sebagai ISO 8601 **dengan
offset zona waktu** (mis. `2026-11-09T12:00:00+08:00`). Bentuk
`Y-m-d H:i:s` yang dihasilkan `__toString()` Carbon sengaja tidak dipakai:
tanpa zona waktu, pembaca audit tidak bisa memastikan itu UTC atau waktu
server, sehingga "masa berlaku diubah pukul berapa" tidak dapat dibuktikan.

**IP pelaku:** diambil dari `Request::ip()`, yang sudah memperhitungkan
`TRUSTED_PROXIES`. Header `X-Forwarded-For` **tidak** dibaca langsung karena
itu bisa dipalsukan klien mana pun. Konsekuensinya: bila `TRUSTED_PROXIES`
belum diisi padahal aplikasi berjalan di belakang reverse proxy, yang tercatat
adalah IP proxy, bukan IP admin — isi `TRUSTED_PROXIES` saat aplikasi
dijalankan.

**Privasi:** penyaringan memakai **whitelist** (deny-by-default), bukan
blacklist. Atribut sensitif yang baru ditambahkan kelak tidak akan ikut
tercatat hanya karena lupa memperbarui daftar hitam. Kegagalan penulisan audit
tidak pernah menggagalkan aksi admin.

Aksi tanpa user login (seeder/scheduler) sengaja **tidak** dicatat agar log
tidak menjadi noise.

Retensi (penjadwalan menyusul di Fase 6):

```bash
# Lihat dulu dampaknya — penghapusan tidak dapat dibatalkan.
vendor/bin/sail artisan audit:prune --days=365 --dry-run
# Baru terapkan.
vendor/bin/sail artisan audit:prune --days=365
```

Penghapusan berjalan per batch (`--chunk`, default 1000) agar tabel audit
tidak terkunci lama, dan memakai indeks `created_at` supaya tidak memindai
seluruh tabel.

---

## 8. Dependency scan & CI

```bash
vendor/bin/sail composer audit            # lokal
# otomatis: .github/workflows/security.yml
```

- Job `dependency-audit`: `composer audit --format=plain --locked`.
- Job `tests`: `php artisan test --compact` pada service PostgreSQL.
- Trigger: `push` (branch `main` + `fase-*`), `pull_request`, dan jadwal
  mingguan (Senin 03:00 UTC).

Temuan yang belum dapat segera diperbaiki didokumentasikan sebagai risiko
yang diterima — **jangan** menurunkan versi paket secara buta.

---

## 9. Ringkasan verifikasi

```bash
cd dashboard-karangasem
vendor/bin/sail artisan test --compact      # 182 test
vendor/bin/sail bin pint --format app       # gaya kode bersih
vendor/bin/sail composer audit              # tanpa advisory
```
