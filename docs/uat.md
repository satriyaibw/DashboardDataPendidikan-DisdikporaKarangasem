# Panduan UAT — Dashboard Data Pendidikan Karangasem

Dokumen ini adalah hasil Fase 7 (QA & UAT). Pertanyaan "apakah sistem
sebenarnya bekerja" dijawab oleh dua hal yang berbeda, dan keduanya ada di
sini:

1. **Test otomatis** — membuktikan perilaku pada level kode. Hijau di sini
   berarti perilakunya benar *sebagaimana dikodekan*.
2. **UAT manual** — membuktikan sistem benar *bagi pengguna*. Hijau di sini
   berarti masalahnya tidak ditemukan user.

Keduanya wajib. UAT di atas suite merah hanya menghasilkan noise; test hijau
bukan bukti bahwa orang bisa memakainya.

---

## 1. Prasyarat Lingkungan

- [ ] Staging ter-deploy dengan `APP_DEBUG=false`
- [ ] Metabase hidup, embedding aktif, signed embedding key terpasang
- [ ] `vendor/bin/sail up -d` berjalan (untuk test otomatis)
- [ ] Akun uji tersedia:

| Akun | Peran | Sifat |
| --- | --- | --- |
| A1 | admin | Sudah melewati setup MFA |
| A2 | admin | Belum melewati setup MFA |
| V1 | vip | Aktif, `expires_at` masih jauh |
| V2 | vip | Aktif, `expires_at` sudah lampau |
| V3 | vip | `is_active = false` |
| V4 | (tanpa peran) | Akun biasa tanpa peran |

> Akun V4 penting: ia membuktikan peran `vip` bersifat eksklusif, bukan
> sekadar "tidak banned".

---

## 2. Status Uji Otomatis

Test suite dijalankan lewat Sail — **jangan pernah** `php artisan test`
langsung di host:

```bash
vendor/bin/sail artisan test --compact
```

Status terakhir Fase 7: **370 test, 787 assertion, hijau.**

### Pemetaan skenario ke test

| ID | Skenario | Otomatis? | Test |
| --- | --- | --- | --- |
| T1 | Guest membuka `/` | Ya | `VipDashboardTest::test_guest_sees_public_dashboard_with_public_embed_url` |
| T2 | Guest membuka `/vip/dashboard` | Ya | `VipAccessTest::test_guest_is_redirected_to_login` |
| T3 | VIP aktif | Ya | `VipAccessTest::test_active_vip_can_access_dashboard` |
| T4 | VIP kedaluwarsa | Ya | `VipAccessTest::test_expired_vip_is_logged_out_and_redirected` |
| T5 | `is_active = false` | Ya | `VipAccessTest::test_inactive_vip_is_rejected` |
| T6 | Token embed | Sebagian | `Unit/MetabaseEmbedServiceTest` (payload JWT) |
| T7 | Admin buat/perpanjang akun | Ya | `AdminActivityLogTest`, `UserObserverTest` |
| T8 | Non-admin membuka `/admin` | Ya | `VipAccessTest::test_non_admin_cannot_access_admin_panel` |
| T9 | Query `analis` ditolak | **Tidak** | Butuh akses `backbone_client` |
| T10 | Dashboard besar + cache | **Tidak** | Butuh Metabase & data nyata |

T6 hanya setengah terotomatis: payload JWT yang benar bisa dibuktikan di test,
tetapi **kedaluwarsa di sisi Metabase** hanya bisa dibuktikan dengan browser
nyata. Lihat §5.

---

## 3. UAT Manual — T9: Query `analis` ditolak

Kontrak: user `analis` hanya boleh **membaca** database `backbone_client`.
Driver database untuk user ini wajib `read-only`, karena satu_query yang lolos
akan mengalir ke dashboard yang dilihat seluruh cuyosyuan kepala daerah.

> **JANGAN** jalankan uji ini terhadap database produksi.

Setup: kredensial read-only `analis` ke `backbone_client`.

| # | Langkah | Ekspektasi | Hasil |
| --- | --- | --- | --- |
| 9.1 | `SELECT count(*) FROM metrics.v_peserta_didik;` | Sukses | ⬜ |
| 9.2 | `INSERT INTO users (email) VALUES ('x@example.com');` | `ERROR: permission denied` | ⬜ |
| 9.3 | `UPDATE metrics.v_peserta_didik SET nama = 'x';` | `ERROR: permission denied` | ⬜ |
| 9.4 | `CREATE TABLE uji (id int);` | `ERROR: must be owner` | ⬜ |
| 9.5 | `DELETE FROM metrics.v_peserta_didik;` | `ERROR: permission denied` | ⬜ |

> **Catatan penting:** tabel `metrics` berisi **view**, bukan tabel fisik.
> Menolak `CREATE` pada skema `metrics` adalah konsekuensi wajar dari-view dan
> bukan bukti proteksi. Yang membuktikan proteksi adalah 9.2, 9.3, dan 9.5.

---

## 4. UAT Manual — T10: Performa & Cache

Metabase memuat data dari `backbone_client`, jadi angka ini hanya bermakna di
staging yang terhubung ke data nyata.

| # | Langkah | Ekspektasi | Hasil |
| --- | --- | --- | --- |
| 10.1 | Buka dashboard publik 3x, catat waktu load tiap opening | < 2 detik; opening ke-2/3 lebih cepat (cache) | ⬜ |
| 10.2 | Buka dashboard VIP 2x | ~ < 3 detik | ⬜ |
| 10.3 | Buka dashboard yang sama di 2 tab | Konsisten, tidak ada timeout | ⬜ |
| 10.4 | `docker exec` container metabase → cek cache hit | Cache aktif | ⬜ |

---

## 5. UAT Manual — T6: Token Embed Kedaluwarsa

Bagian yang tidak bisa dibuktikan oleh test otomatis. Token embed punya TTL
pendek (default 600 detik) dan halaman VIP memuat ulang token lewat polling.

| # | Langkah | Ekspektasi | Hasil |
| --- | --- | --- | --- |
| 6.1 | Login sebagai V1, buka `/vip/dashboard` | Dashboard ter-render, bukan halaman error | ⬜ |
| 6.2 | Tunggu lebih lama dari TTL tanpa me-refresh halaman | Iframe tetap ter-render (polling mengambil token baru) | ⬜ |
| 6.3 | Klik refresh browser (F5) | Dashboard kembali normal | ⬜ |
| 6.4 | Buka DevTools → Network → cari `/vip/embed-url` | Request sukses, `Cache-Control: no-store` | ⬜ |
| 6.5 | Klik kanan iframe → "Reload frame" setelah token kedaluwarsa | Tidak menampilkan halaman error Metabase | ⬜ |

### Skenario negatif

| # | Langkah | Ekspektasi | Hasil |
| --- | --- | --- | --- |
| 6.6 | Salin `src` iframe, gunakan ulang setelah 2x TTL di browser lain | Ditolak Metabase | ⬜ |
| 6.7 | Buka `/vip/dashboard` tanpa login | Redirect ke `/login` | ⬜ |
| 6.8 | Ubah `X-Forwarded-For` dipalsukan di browser | Tidak memengaruhi keputusan akses | ⬜ |

---

## 6. UAT Manual — Perilaku Admin

| # | Langkah | Ekspektasi | Hasil |
| --- | --- | --- | --- |
| 7.1 | Login sebagai A1 (sudah MFA) | Masuk ke `/admin` | ⬜ |
| 7.2 | Login sebagai A2 (belum MFA) | Diarahkan ke setup MFA | ⬜ |
| 7.3 | Buat akun baru, beri peran `vip` | Akun dibuat, muncul di daftar | ⬜ |
| 7.4 | Perpanjang masa aktif 30 hari | Masa aktif bertambah tepat 30 hari | ⬜ |
| 7.8 | Periksa jejak audit tiap aksi | `user.created` / `user.updated` / `user.deleted` tercatat | ⬜ |
| 7.9 | Coba buat akun dengan password lemah | Ditolak dengan pesan jelas | ⬜ |
| 7.10 | Klik "Keluar", lalu buka lagi `/admin` | Diarahkan ke login | ⬜ |
| 7.11 | Buat akun VIP baru, lalu baca jejak auditnya | **Dua** baris: `user.created` lalu `user.role_attached` berisi `vip` | ⬜ |
| 7.12 | Cabut peran `vip` dari sebuah akun | Baris `user.role_detached` muncul berisi `vip` | ⬜ |

> Langkah 7.11–7.12 memverifikasi perbaikan D-1 (lihat §8). Peran tidak lagi
> muncul pada baris `user.created`, melainkan pada barisnya sendiri.

---

## 7. Bukti

Simpan bukti sebagai `docs/uat-evidence/<ID>-<nama>.png`.

| Skenario | Berkas bukti |
| --- | --- |
| T1 | `t1-guest-public-dashboard.png` |
| T2 | `t2-guest-redirected-login.png` |
| T3 | `t3-active-vip-dashboard.png` |
| T4 | `t4-expired-vip-redirect.png` |
| T5 | `t5-inactive-vip-redirect.png` |
| T6 | `t6-embed-token-refresh.png` |
| T7 | `t7-admin-audit-trail.png` |
| T8 | `t8-non-admin-forbidden.png` |
| T9 | `t9-analis-readonly-proof.png` |
| T10 | `t10-cache-timing.png` |

---

## 8. Defek yang Ditemukan & Diperbaiki

### D-1 — `roles` pada audit `user.created` selalu kosong — **SUDAH DIPERBAIKI**

- **Tingkat:** Sedang
- **Lokasi:** `app/Observers/UserObserver.php`, `createdProperties()`
- **Gejala (sebelum perbaikan):** Baris audit `user.created` selalu mencatat
  `"roles": {"before": null, "after": ""}`, bahkan ketika akun dibuat dengan
  peran `vip`.
- **Akar masalah:** Spatie menunda `assignRole()` sampai model tersimpan,
  sehingga saat event `created` dipanggil pivot peran **belum ada sama
  sekali**. Membaca lewat relasi maupun query builder sama-sama kosong.
- **Dampak:** Jejak audit tidak bisa menunjukkan akun dibuat sebagai VIP atau
  non-VIP — persis informasi yang paling dibutuhkan saat operator ditanya
  "akun ini sebenarnya dibuat untuk siapa?".

#### Solusi

Peran tidak lagi dicatat pada `user.created`. Perubahan peran dipancarkan Spatie
lewat `RoleAttachedEvent`/`RoleDetachedEvent`, yang dipancarkan **setelah** pivot
terpasang. `RoleAssignmentObserver` mendengar event itu dan menuliskan baris
audit tersendiri:

| Aksi | Arti |
| --- | --- |
| `user.role_attached` | Peran baru diberikan |
| `user.role_detached` | Peran dicabut |

Event Spatie memerlukan `permission.events_enabled` — sakelarnya dinyalakan di
`AppServiceProvider::registerRoleAssignmentAudit()`.

> **Konsekuensi yang perlu diketahui saat membaca log:** satu tindakan "buat
> akun VIP" kini menghasilkan **dua** baris audit: `user.created` (data akun)
> lalu `user.role_attached` (perannya). Itu bukan duplikasi — itu dua peristiwa
> berbeda yang memang terjadi berurutan.

#### Test penjaga

`tests/Feature/RoleAssignmentObserverTest.php` (14 test), termasuk:

- `test_spatie_role_events_are_enabled` — menjaga sakelar tetap menyala
- `test_detaching_a_role_is_recorded` — nama peran pada detach dibaca dari
  tabel peran, bukan relasi (relasi sudah kosong setelah pivot dilepas)
- `test_creating_an_account_with_a_role_leaves_a_complete_trail` — kasus model
  baru, saat event tiba sebelum `save()`

### D-2 — satu pemasangan peran menghasilkan banyak baris audit — **SUDAH DIPERBAIKI**

- **Tingkat:** Tinggi
- **Lokasi:** `app/Observers/RoleAssignmentObserver.php`, `recordAfterSave()`
- **Gejala (sebelum perbaikan):** baris `user.role_attached` bertambah terus
  setiap kali akun disimpan ulang — 1 → 2 → 3 untuk satu peristiwa memasang
  peran. Jejak audit flooded dengan salinan dari satu aksi yang sama, sehingga
  reviewer tidak bisa membedakan "admin memasang peran" dari "sistem menyimpan
  ulang akun".
- **Akar masalah:** `$model->saved(...)` mendaftarkan listener ke **dispatcher
  global** dengan kunci nama class, bukan ke instance model, dan tidak pernah
  dilepas. Listener itu karena itu menyala untuk setiap `save()` berikutnya
  selama proses berjalan. Spatiesendiri memakai pola penanda `&$saved` untuk
  listener-nya; pola yang sama belum dipakai di sini.
- **Solusi:** penanda sekali-pakai `&$recorded` pada listener yang ditunda.
  Selain itu, nama peran kini dibaca dari `rolesOrIds` event saat event
  diterima — tabel peran berdiri sendiri, jadi nilainya sudah benar sejak awal
  dan tidak bergantung pada urutan listener Spatie.

#### Test penjaga

- `test_saving_the_account_again_does_not_duplicate_the_role_audit_entry` —
  tanpa penanda, test ini gagal dengan "Failed asserting that 3 is identical
  to 1"
- `test_saving_a_different_account_records_no_role_entry_for_it` — listener
  yang tertunda tidak boleh menyalin jejak audit ke akun lain
- `test_the_entry_names_only_the_role_that_was_actually_attached` — baris audit
  menyebut peran yang dipasang, bukan gabungan seluruh peran akun

### Catatan (bukan defek)

Menolak VIP memang menghasilkan beberapa query tulis: rotasi `remember_token`
oleh `Auth::logout()` dan cache rate limiter/session. Semuanya berasal dari
framework, bukan dari keputusan middleware, dan tidak mengubah status hak
akses. Kontrak "middleware tidak boleh menulis" diuji secara spesifik pada
kolom `is_active`/`expires_at` — lihat `CheckVipAccessTest`.

---

## 9. Alur Debugging

Tidak perlu MCP untuk menjalankan test terisolasi:

```bash
# Log real-time
vendor/bin/sail artisan pail

# Test terisolasi
vendor/bin/sail artisan test --filter=test_expired_vip
vendor/bin/sail bin phpunit tests/Unit/MetabaseEmbedServiceTest.php

# Debug internal
vendor/bin/sail artisan tinker --execute 'app(\App\Services\MetabaseEmbedService::class)->signedUrl();'

# Scheduler
vendor/bin/sail artisan schedule:list
vendor/bin/sail artisan vip:deactivate-expired --dry-run

# Embedding & keamanan
vendor/bin/sail artisan metabase:check-embedding
vendor/bin/sail artisan security:scan-pii
```

### Gejala → Penyebab → Solusi

| Gejala | Penyebab | Solusi |
| --- | --- | --- |
| Semua test gagal, "SQLSTATE connection refused" | Sail mati | `vendor/bin/sail up -d` |
| `mbstring` / `pdo_pgsql` error | Container belum siap | `vendor/bin/sail up -d` lalu tunggu |
| Test MFA gagal | `CACHE_STORE` diubah jadi `array` | Kembalikan ke `database` |
| CSP memblokir iframe di browser | `METABASE_SITE_URL` ≠ origin sebenarnya | `artisan metabase:check-embedding` |
| Token embed 403 di Metabase | Secret tidak identik di dua sisi | Cek `MB_EMBEDDING_SECRET_KEY` |
| `onOneServer()` tidak serialize | Cache store per-proses | Pakai `database`/`redis` |

### MCP yang tersedia

Terpasang **di dalam project ini saja** (`.mcp.json`), tidak mengubah config
OpenCode global:

| Server | Fungsi |
| --- | --- |
| `laravel-boost` | Database query, schema, URL absolut, log browser |
| `playwright` | E2E test, screenshot, console log |
| `filesystem` | Membaca `storage/logs/` |
| `sequential-thinking` | Debugging terstruktur |

> **Tidak ada MCP Postgres.** Itu hanya menambah permukaan serang ke database
> yang sudah dilindungi. Query debugging lewat `laravel-boost` sudah cukup,
> dan selalu ke `dash_app` saja.
