# Plan Implementasi — Fase 7: QA & UAT + Unit Testing + Debugging

> **Status:** READY FOR IMPLEMENTATION
> **Audience:** Junior programmer / AI agent berbiaya rendah
> **Sumber Kebenaran:** `MasterPlan.md` §11 (Fase 7) + audit kode existing
> **Target repo:** `dashboard-karangasem/`
> **Runtime:** Laravel Sail (PHP 8.5, PostgreSQL 18, container terpisah)

---

## 0. Aturan Emas (Baca Dulu)

Aturan ini berasal dari `AGENTS.md` project dan **tidak boleh dilanggar**:

1. **Selalu jalankan perintah lewat Sail.** Semua perintah PHP/Artisan/Composer/Node **wajib** berawalan `vendor/bin/sail`. Contoh salah: `php artisan test`. Contoh benar: `vendor/bin/sail artisan test`.
2. **Jangan ubah dependency tanpa izin.** Tidak ada `composer require` baru di fase ini. Yang dibutuhkan sudah terpasang.
3. **Format dengan Pint.** Setelah mengubah file PHP: `vendor/bin/sail bin pint --dirty`.
4. **Test terputus = belum selesai.** Kalau ada test merah, perbaiki — jangan di-`skip`.
5. **Ambig → berhenti & tanya.** Jangan menebak nama tabel/kolom/perilaku.
6. **Laravel tidak boleh menyentuh `backbone_client`.** Query test hanya ke `dash_app` (via Sail `pgsql`).

---

## 1. Ringkasan

| Pilar | Tujuan | Output |
|-------|--------|--------|
| **A. Unit & Feature Test** | Menutup gap coverage yang sudah teridentifikasi | Test suite hijau |
| **B. UAT** | Membuktikan skenario T1–T10 di staging | `docs/uat.md` terisi |
| **C. Debugging** | Alat & SOP saat ada masalah | MCP tools + runbook |

**Estimasi:** 4–6 hari kerja. **Prasyarat:** Fase 1–6 selesai, `vendor/bin/sail up -d` berjalan.

---

## 2. Audit Kode Existing (Fakta Terverifikasi)

### 2.1 Test Suite Saat Ini

**191 test methods di 20 file.** Ini foundations yang sudah kuat — pekerjaan fase ini adalah *melengkapi*, bukan *menulis dari nol*.

| File | Jumlah | Area |
|------|--------|------|
| `Feature/SecurityHeadersTest.php` | 22 | Header keamanan (CSP, HSTS, Permissions-Policy) |
| `Feature/SecurityScanPiiTest.php` | 18 | Deteksi PII di kartu Metabase |
| `Feature/VipDashboardTest.php` | 17 | Halaman VIP, token, CSP, rate limit |
| `Unit/MetabaseEmbedServiceTest.php` | 18 | JWT HS256, validasi secret/URL/param |
| `Feature/AdminActivityLogTest.php` | 13 | Audit trail aksi admin |
| `Feature/DeactivateExpiredVipsCommandTest.php` | 13 | Selector penonaktifan VIP |
| `Feature/MetabaseCheckEmbeddingTest.php` | 13 | Health check embedding |
| `Feature/AdminMultiFactorAuthenticationTest.php` | 11 | MFA admin (TOTP + replay guard) |
| `Feature/VipAccessTest.php` | 10 | Otorisasi akses VIP |
| `Feature/HealthCheckTest.php` | 10 | Endpoint `/up` |
| `Feature/AuditPruneCommandTest.php` | 8 | Retensi audit log |
| `Feature/ScheduleRegistrationTest.php` | 8 | Registrasi scheduler |
| `Feature/PasswordPolicyTest.php` | 7 | Validasi password (UI Filament) |
| `Feature/MetabaseCspHeadersTest.php` | 6 | CSP `frame-src` per-rute |
| `Feature/PasswordPolicyServiceTest.php` | 5 | Service kebijakan password |
| `Feature/RequestIdLoggingTest.php` | 5 | Request ID header |
| `Feature/SessionCookieTest.php` | 4 | Atribut cookie sesi |
| `Feature/AdminActivityLogRetentionTest.php` | 1 | `ON DELETE SET NULL` |
| `Feature/ExampleTest.php` + `Unit/ExampleTest.php` | 2 | Template (boleh dihapus) |

Support: `tests/Concerns/HasAdminUser.php` (trait admin + MFA), `tests/TestCase.php`.

### 2.2 Gap yang Riil (dari kode yang belum ada test-nya)

| Kode Produksi | Status Test | Prioritas |
|---------------|-------------|-----------|
| `App\Services\AdminActivityLogger` | Tidak ada test langsung (hanya lewat Filament) | **Tinggi** |
| `App\Services\PiiScanner` | Tidak ada test langsung (hanya lewat command) | **Tinggi** |
| `App\Services\SafeErrorMessage` | Tidak ada test | **Tinggi** |
| `App\Services\MetabaseOrigin` | Tidak ada test langsung | **Tinggi** |
| `App\Services\TrustedProxyList` | Tidak ada test langsung | Sedang |
| `App\Http\Controllers\VipLoginController` | Parsial (2 dari ~5 jalur) | **Tinggi** |
| `App\Http\Middleware\CheckVipAccess` | Tidak ada test langsung | Sedang |
| `App\Observers\UserObserver` | Tidak ada test langsung | Sedang |
| `database/migrations/*` | Tidak ada test rollback | Sedang |
| Blade views (`public.*`, `vip.*`) | Tidak ada test render | Rendah |

### 2.3 Konvensi yang Harus Diikuti

Sudah ada pola kuat di repo — **jangan kenakan gaya sendiri**:

```php
// 1. Nama test: test_<skenario>_<harapan> (snake_case, bahasa Indonesia)
public function test_expired_vip_is_logged_out_and_redirected(): void

// 2. Komentar PHPDoc menjelaskan MENGAPA, bukan APA.
public function test_it_never_touches_vip_accounts_without_expiry(): void
{
    // `expires_at` null berarti langganan tanpa batas waktu, bukan
    // langganan yang sudah habis. Menonaktifkannya akan memutus akses
    // pelanggan yang masih berhak.

// 3. Helper dilindungi + PHPDoc. Nama deskriptif, bukan singkatan.
protected function strictTransportSecurityFor(string $url): Response

// 4. Data dibuat lewat factory + state, bukan insert manual.
$user = User::factory()->create(['is_active' => true]);

// 5. Konstanta/env di-set di setUp(), bukan di dalam test.
protected function setUp(): void { config(['metabase.site_url' => '...']); }
```

**Pola penting dari `ScheduleRegistrationTest.php`:** assertion behavioral (hitung `nextRunDate()`), **bukan** assertion pada teks output CLI yang bisa berubah antar versi.

**Pola penting dari `AdminMultiFactorAuthenticationTest.php`:** test *guard* yang memverifikasi konfigurasi test sendiri masih valid (mis. `CACHE_STORE` bukan `array`). Ikuti pola ini bila menambah test yang bergantung pada konfigurasi.

---

## 3. TASK A — Menutup Gap Test

### TASK A.1 — Unit Test Service Layer (prioritas utama)

Buat dengan: `vendor/bin/sail artisan make:test --phpunit --unit <Nama>`

> **Catatan:** project memakai PHPUnit, bukan Pest. `phpunit.xml` sudah punya `tests/Unit` + `tests/Feature`.

#### A.1.1 `AdminActivityLoggerTest`
**Files:** `tests/Unit/AdminActivityLoggerTest.php`
**Baca dulu:** `app/Services/AdminActivityLogger.php`

| # | Test Case | Mengapa penting |
|---|-----------|-----------------|
| 1 | Aktor log valid → `actor_id` terisi | dasar |
| 2 | Tanpa sesi admin (`actor_id` null) → tetap bisa log | dipakai scheduler tanpa sesi |
| 3 | `properties` difilter whitelist — `password`/`email` tidak ikut | **prevensi kebocoran PII ke audit** |
| 4 | `subject_type` berupa FQCN class | konsistensi skema |
| 5 | Aksi dengan `subject` null tidak error | robustness |

#### A.1.2 `PiiScannerTest`
**Files:** `tests/Unit/PiiScannerTest.php`
**Baca dulu:** `app/Services/PiiScanner.php`

| # | Test Case | Mengapa penting |
|---|-----------|-----------------|
| 1 | SQL native memuat `nama` → terdeteksi | |
| 2 | SQL native bersih dari `metrics.*` → tidak terdeteksi | false-positive bisa membuat dashboard sah ditolak |
| 3 | Kolom PII di daftar panjang (`nik`, `no_kk`, `nuptk`, `nisn`) | kelengkapan daftar |
| 4 | Kata PII sebagai substring identifier (`kode_nama_sekolah`) **tidak** false-positive | false-positive = dashboard ditolak tanpa alasan |
| 5 | Kata PII di dalam string literal / komentar SQL tidak memicu | false-positive |

> Use `#[DataProvider]` untuk 4 & 5 — sudah jadi pola di `SecurityHeadersTest` dan `PasswordPolicyServiceTest`.

#### A.1.3 `SafeErrorMessageTest`
**Files:** `tests/Unit/SafeErrorMessageTest.php`
**Baca dulu:** `app/Services/SafeErrorMessage.php`

| # | Test Case |
|---|-----------|
| 1 | Exception generik → pesan aman tanpa detail internal |
| 2 | Pesan asli **tidak pernah** mengandung `SQLSTATE` / host / password |
| 3 | Waktu (`APP_DEBUG=true`) tetap tidak membocorkan (fail-safe) |

#### A.1.4 `MetabaseOriginTest`
**Files:** `tests/Unit/MetabaseOriginTest.php`
**Baca dulu:** `app/Services/MetabaseOrigin.php`

| # | Test Case |
|---|-----------|
| 1 | URL valid `https://mb.test` → origin ternormalisasi |
| 2 | Trailing slash & port default dihapus |
| 3 | IPv6 `https://[::1]:3000` → kurung siku dipertahankan |
| 4 | URL berbahaya (`javascript:`, kredensial, header injection) ditolak |

> **Reuse:** banyak kasus ini sudah ada di `SecurityHeadersTest` (`hostileMetabaseOrigins`). **Jangan duplikasi** — kalau perilakunya sudah ter-cover di sana, tambahkan test hanya untuk jalur yang belum ter-cover.

**DoD TASK A.1:**
- [ ] Semua test hijau
- [ ] Tidak ada duplikasi dengan test existing (cek dulu!)
- [ ] Pint formatting bersih

---

### TASK A.2 — Feature Test Controller & Middleware

#### A.2.1 `VipLoginControllerTest` (lengkapi)
**Files:** `tests/Feature/VipLoginControllerTest.php`
**Baca dulu:** `app/Http/Controllers/VipLoginController.php`, `routes/web.php`

Jalur yang **belum** ter-cover di `VipAccessTest`:

| # | Test Case | Ekspektasi |
|---|-----------|------------|
| 1 | GET `/vip/login` authenticated → redirect (tak ada form login untuk user login) | 302 |
| 2 | Kredensial kosong → error validasi, bukan 500 | 302 + session error |
| 3 | Email tidak valid → error validasi | 302 + session error |
| 4 | Login gagal → `RateLimiter` naik, pesan "Kredensial tidak valid" | 302 |
| 5 | Rate limit terlampaui → pesan "Terlalu banyak percobaan" | 302 |
| 6 | Login sukses → `RateLimiter` dibersihkan | 302 → `/vip/dashboard` |
| 7 | Key rate limit memakai IP berbeda → tidak saling memblokir | 302 |
| 8 | Non-VIP login sukses → ditolak | guest + session error |

> **Peringatan:** `VipAccessTest::test_login_rate_limiting_is_enforced` memakai key `email|ip`. Karena `SESSION_DRIVER=array` tapi `CACHE_STORE=database`, pastikan test 7 benar-benar memakai IP berbeda (`$this->withServerVariables` / header `X-Forwarded-For` **hanya** kalau `TRUSTED_PROXIES` mengizinkan — sudah di-set `127.0.0.1` di `phpunit.xml`).

#### A.2.2 `CheckVipAccessMiddlewareTest`
**Files:** `tests/Feature/CheckVipAccessTest.php`
**Baca dulu:** `app/Http/Middleware/CheckVipAccess.php`

| # | Test Case | Kenapa |
|---|-----------|--------|
| 1 | Guest → redirect login | |
| 2 | VIP aktif → lolos | |
| 3 | VIP `is_active=false` → ditolak **dan** tidak mengubah DB | **MasterPlan §2.5: middleware tidak boleh menulis side-effect** |
| 4 | VIP `expires_at` lampau → ditolak tanpa mengubah DB | idem |
| 5 | `expires_at` tepat `now()` → ditolak (boundary `<=` vs `<`) | off-by-one |
| 6 | Admin (role `admin`, tanpa `vip`) → ditolak | role exclusivity |
| 7 | User dengan `is_active=false` tapi VIP aktif → **ditolak** | urutan cek |

> Test #3 dan #4 adalah **kontrak arsitektur**, bukan sekadar bug test. Kalau masih ada, systemic race condition sedang terjadi.

#### A.2.3 `UserObserverTest`
**Files:** `tests/Feature/UserObserverTest.php`
**Baca dulu:** `app/Observers/UserObserver.php`

| # | Test Case |
|---|-----------|
| 1 | Update oleh admin di panel → log `user.updated` |
| 2 | `password` diubah → **tidak** masuk `properties` |
| 3 | Update tanpa sesi admin → tidak menghasilkan log (sudah jadi perilaku scheduler) |
| 4 | Perubahan tidak nyata (nilai sama) → tidak log |

**DoD TASK A.2:**
- [ ] Semua test hijau
- [ ] Test #3/#4 di A.2.2 secara eksplisit menguji "tidak ada write"

---

### TASK A.3 — Test Migration & Integritas Data

#### A.3.1 Rollback migration
```bash
# Uji manual (JANGAN di DB produksi):
vendor/bin/sail artisan migrate:fresh --env=testing
vendor/bin/sail artisan migrate:rollback --step=1
vendor/bin/sail artisan migrate --env=testing
```

#### A.3.2 Foreign key & index
**Files:** `tests/Feature/DatabaseConstraintsTest.php`

| # | Test Case |
|---|-----------|
| 1 | `model_has_roles` terikat user via FK — hapus user → relasi ikut hilang |
| 2 | `admin_activity_logs.actor_id` → `ON DELETE SET NULL` (sudah ada di `AdminActivityLogRetentionTest` — **tambahkan kasus baru saja, jangan duplikasi**) |
| 3 | `admin_activity_logs.subject_id` → bertahan setelah user dihapus |
| 4 | `expires_at` punya index (query scheduler lewat 80k baris) |

> Verifikasi index via query plan, bukan asumsi:
> ```php
> $plan = DB::select('EXPLAIN SELECT * FROM users WHERE expires_at <= now() AND is_active = true');
> $this->assertStringContainsString('Index', $plan[0]->{'QUERY PLAN'} ?? '');
> ```
> Kalau ternyata seq scan pada tabel kecil, **jangan** asserts — dokumentasikan di `docs/data-dictionary.md` sebagai catatan performa.

**DoD TASK A.3:**
- [ ] `migrate:fresh` + `rollback` sukses di environment test
- [ ] Tidak ada duplikasi dengan `AdminActivityLogRetentionTest`

---

### TASK A.4 — View Rendering (opsional, prioritas rendah)

`resources/views/public/dashboard.blade.php` & `vip/dashboard.blade.php`

| # | Test Case |
|---|-----------|
| 1 | Iframe punya `title` yang deskriptif (aksesibilitas) |
| 2 | Iframe punya `loading="lazy"` + `referrerpolicy` |
| 3 | Halaman VIP tidak menampilkan secret/token di luar `src` iframe |
| 4 | Halaman VIP tidak punya data hardcoded PII |

---

## 4. TASK B — UAT (docs/uat.md)

> **Penting:** T1–T10 **tidak bisa** semuanya diuji otomatis. T1, T9, T10 butuh browser & akses database backbone → **manual**.

### 4.1 Pemetaan Skenario → Otomatisi

| ID | Skenario | Otomatis? | Test File |
|----|----------|-----------|-----------|
| T1 | Guest buka `/` | ✅ Sudah | `VipDashboardTest::test_guest_sees_public_dashboard_with_public_embed_url` |
| T2 | Guest `/vip/dashboard` | ✅ Sudah | `VipAccessTest::test_guest_is_redirected_to_login` |
| T3 | VIP aktif | ✅ Sudah | `VipAccessTest::test_active_vip_can_access_dashboard` |
| T4 | VIP kedaluwarsa | ✅ Sudah | `VipAccessTest::test_expired_vip_is_logged_out_and_redirected` |
| T5 | `is_active=false` | ✅ Sudah | `VipAccessTest::test_inactive_vip_is_rejected` |
| T6 | Token embed | ⚠️ Sebagian | `Unit/MetabaseEmbedServiceTest` (payload) — **kedaluwarsa di sisi Metabase = manual** |
| T7 | Admin buat/perpanjang | ✅ Sudah | `AdminActivityLogTest`, `PasswordPolicyTest` |
| T8 | Non-admin → `/admin` | ✅ Sudah | `VipAccessTest::test_non_admin_cannot_access_admin_panel` |
| T9 | Query `analis` write gagal | ❌ Manual | Butuh akses `backbone_client` |
| T10 | Dashboard besar + cache | ❌ Manual | Butuh Metabase berjalan |

### 4.2 Template `docs/uat.md`

```markdown
# Panduan UAT — Dashboard Data Pendidikan Karangasem

## Prasyarat Lingkungan
- Staging ter-deploy, `APP_DEBUG=false`
- Metabase hidup & embedding aktif
- Akun uji: 1 admin (MFA), 1 VIP aktif, 1 VIP kedaluwarsa, 1 non-VIP

## T9 — Query `analis` ditolak
**Setup:** kredensial read-only `analis` ke `backbone_client`
| # | Langkah | Ekspektasi | Hasil |
|---|---------|------------|-------|
| 9.1 | `SELECT count(*) FROM metrics.v_peserta_didik;` | Sukses | ⬜ |
| 9.2 | `INSERT INTO users (...) VALUES (...);` | `ERROR: permission denied` | ⬜ |
| 9.3 | `UPDATE metrics.v_peserta_didik SET ...;` | `ERROR: permission denied` | ⬜ |
| 9.4 | `CREATE TABLE x (...);` | `ERROR: must be owner` | ⬜ |

## T10 — Performa & cache
| # | Langkah | Ekspektasi | Hasil |
|---|---------|------------|-------|
| 10.1 | Buka dashboard publik 3x, catat waktu load | < 2 detik, load 2/3 lebih cepat (cache) | ⬜ |
| 10.2 | Buka dashboard VIP 2x | ~< 3 detik | ⬜ |
| 10.3 | `docker exec` metabase → cek cache hit | cache aktif | ⬜ |

## Bukti
Tempel screenshot ke `docs/uat-evidence/<ID>-<nama>.png`
```

**DoD TASK B:**
- [ ] `docs/uat.md` ada & terisi
- [ ] T1–T10 punya bukti
- [ ] Tidak ada bug kritis terbuka

---

## 5. TASK C — MCP Tools & Debugging

### 5.1 Prinsip Install

> **WAJIB:** MCP di-install **di dalam project ini saja** (`.mcp.json` di `dashboard-karangasem/`). **Dilarang** mengubah config OpenCode global di `~/.config/opencode/`.

### 5.2 Tools yang Terverifikasi (sudah dicek ke npm & GitHub)

| Tool | Paket / Repo | Status Verifikasi | Fungsi |
|------|--------------|-------------------|--------|
| **Playwright MCP** | `@playwright/mcp` (npm) | ✅ npm 0.0.83, bin `playwright-mcp` | E2E test, screenshot, console log |
| **Filesystem MCP** | `@modelcontextprotocol/server-filesystem` | ✅ npm 2026.8.31 | Baca `storage/logs/` |
| **Sequential Thinking** | `@modelcontextprotocol/server-sequential-thinking` | ✅ npm 2026.8.31 | Debugging terstruktur |
| **PHP Static Analysis** | `PhpCodeArcheology/PhpCodeArcheology` | ✅ GitHub 200 | Analisis kode PHP + coverage + test coverage |
| **Postgres (read-only)** | `Arun-kc/schemabrain` | ✅ GitHub 200 | Query Postgres read-only + **PII refusal** |

> **DIBATAS:** `@modelcontextprotocol/server-postgres` **sudah DEPRECATED** (npm: "Package no longer supported"). **Jangan pakai.** Pakai `schemabrain` atau `crystaldba/postgres-mcp`.
> **DIBATAS:** paket `@modelcontextprotocol/server-security-scan` **TIDAK ADA** di npm (404). Untuk keamanan, pakai `composer audit` + `artisan security:scan-pii` yang sudah ada.

### 5.3 Konfigurasi `.mcp.json`

File ini **sudah ada** di `dashboard-karangasem/.mcp.json` (berisi `laravel-boost`). **Tambahkan**, jangan timpa:

```json
{
  "mcpServers": {
    "laravel-boost": {
      "command": "vendor/bin/sail",
      "args": ["artisan", "boost:mcp"]
    },
    "playwright": {
      "command": "npx",
      "args": ["-y", "@playwright/mcp@latest", "--browser", "chromium"]
    },
    "filesystem": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem@latest", "./storage/logs"]
    },
    "sequential-thinking": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-sequential-thinking@latest"]
    }
  }
}
```

### 5.4 Postgres MCP (opsional — untuk debugging query)

> ⚠️ **Peringatan keamanan:** MasterPlan §4 melarang Laravel menyentuh `backbone_client`. MCP Postgres untuk **debugging test DB saja**.

Karena `phpunit.xml` memakai `DB_HOST=pgsql` (nama container, hanya valid **di dalam** network Sail), sedangkan MCP berjalan di **host**, gunakan port yang di-forward:

```bash
# Port forward sudah ada di compose.yaml → FORWARD_DB_PORT (default 5432)
# Akses dari host: 127.0.0.1:5432
```

```json
"postgres-app-db": {
  "command": "uvx",
  "args": ["mcp-server-postgres"],
  "env": {
    "DATABASE_URL": "postgresql://${DB_USERNAME}:${DB_PASSWORD}@127.0.0.1:${FORWARD_DB_PORT:-5432}/${DB_DATABASE}"
  }
}
```

> **Jangan pernah** arahkan MCP Postgres ke `backbone_client`. Hanya `dash_app`.

### 5.5 Verifikasi Install

```bash
# 1. Pastikan Sail jalan
vendor/bin/sail up -d

# 2. Pastikan MCP terpicu
#    Sesuaikan: beberapa client butuh restart setelah .mcp.json berubah

# 3. Test manual
npx @playwright/mcp --help
npx @modelcontextprotocol/server-filesystem --help
```

### 5.6 Debugging Without MCP

Perangkat utama project ini **sudah cukup** tanpa MCP:

```bash
# Log real-time
vendor/bin/sail artisan pail

# Test terisolasi
vendor/bin/sail artisan test --filter=test_expired_vip
vendor/bin/sail bin phpunit tests/Unit/MetabaseEmbedServiceTest.php

# Debug internal (paling berguna)
vendor/bin/sail artisan tinker --execute 'app(\App\Services\MetabaseEmbedService::class)->signedUrl();'

# Scheduler
vendor/bin/sail artisan schedule:list
vendor/bin/sail artisan vip:deactivate-expired --dry-run

# Embedding & keamanan
vendor/bin/sail artisan metabase:check-embedding
vendor/bin/sail artisan security:scan-pii

# Quality gate
vendor/bin/sail composer test
vendor/bin/sail bin pint --dirty
composer audit
```

### 5.7 Common Issues → Solution

| Gejala | Likely Cause | Solusi |
|--------|--------------|--------|
| Test gagal semua, "SQLSTATE connection refused" | Sail mati | `vendor/bin/sail up -d` |
| `mbstring`/`pdo_pgsql` error | Container belum siap | `vendor/bin/sail up -d && sleep 5` |
| MFA test gagal | `CACHE_STORE` diubah jadi `array` | Guard di `AdminMultiFactorAuthenticationTest` sudah menangkap ini |
| CSP blokir iframe di browser | `METABASE_SITE_URL` ≠ origin sebenarnya | `metabase:check-embedding` |
| Token embed 403 di Metabase | Secret tidak identik dua sisi | `metabase:check-embedding` + cek `MB_EMBEDDING_SECRET_KEY` |
| `onOneServer()` tidak Serialize | Cache store per-proses | Pakai `database`/`redis` |
| Pint mengubah file → test gagal | Format | Commit pint + test dalam 1 PR |

---

## 6. Execution Order

```
┌──────────────────────────────────────────────────────────┐
│ TASK A.1  Unit Tests Service          ~1.5 hari         │
│   ↓ PR #1: tests/Unit/{AdminActivityLogger,PiiScanner,   │
│           SafeErrorMessage,MetabaseOrigin}Test.php        │
│                                                          │
│ TASK A.2  Controller & Middleware     ~1 hari            │
│   ↓ PR #2: tests/Feature/VipLoginControllerTest.php,     │
│           CheckVipAccessTest.php, UserObserverTest.php   │
│                                                          │
│ TASK A.3  Migration & Constraints      ~0.5 hari          │
│   ↓ PR #3: tests/Feature/DatabaseConstraintsTest.php     │
│                                                          │
│ TASK A.4  View Rendering (opsional)    ~0.5 hari          │
│   ↓ PR #4 (opsional)                                    │
│                                                          │
│ TASK B    UAT Manual                  ~1-2 hari          │
│   ↓ docs/uat.md + docs/uat-evidence/                    │
│                                                          │
│ TASK C    MCP Setup                   ~0.5 hari          │
│   ↓ .mcp.json + verifikasi                              │
└──────────────────────────────────────────────────────────┘
```

**Urutan wajib:** A → B. Jangan UAT sebelum test suite hijau — UAT di atas suite merah hanya menghasilkan noise.

---

## 7. Definition of Done

### Per-TASK
- [ ] **A.1** — Service baru punya test; tidak ada duplikat dengan test existing
- [ ] **A.2** — `CheckVipAccess` terbukti **tidak menulis** ke DB
- [ ] **A.3** — `migrate:fresh` + `rollback` sukses
- [ ] **A.4** — (opsional) view/accessibility ter-cover
- [ ] **B** — T1–T10 punya bukti di `docs/uat-evidence/`
- [ ] **C** — MCP terpicu, tidak ada perubahan ke config global

### Global (WAJIB semua true)
- [ ] `vendor/bin/sail artisan test` → **100% hijau**
- [ ] `vendor/bin/sail bin pint --dirty` → **tidak ada perubahan**
- [ ] `composer audit` → **tidak ada vulnerability baru**
- [ ] Tidak ada test yang di-`skip`/`markTestSkipped`
- [ ] Tidak ada dependency baru
- [ ] Tidak ada referensi ke `backbone_client` di kode test

---

## 8. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|--------|--------|----------|
| Duplikasi test dengan 191 test existing | Waktu terbuang, test redundan | **Baca test existing dulu** sebelum tulis test baru |
| Menambah dependency (mis. Xdebug/Pest) | Lockfile rusak, approval lama | Pakai yang sudah ada; Xdebug via `SAIL_XDEBUG_MODE` |
| MCP menulis ke `backbone_client` | **Pelanggaran MasterPlan §4** | Arahkan hanya ke `dash_app`; read-only |
| Test lambat karena `RefreshDatabase` | Feedback loop panjang | Gunakan `DatabaseTransactions` di test read-only |
| Flaky test (waktu/w timezone) | CI merah acak | `Carbon::setTestNow()` + `try/finally` — sudah jadi pola di repo |
| UAT butuh Metabase hidup | Testing terblokir | Jalankan TASK A sepenuhnya lebih dulu |
| OTP MFA test butuh `APP_AUTHENTICATION_SECRET` | Test gagal | `HasAdminUser::adminUserWithMultiFactorAuthentication()` sudah handle |

---

## 9. Referensi

### Dokumentasi (URL sudah diverifikasi 200 OK)
- Laravel 13 Testing — https://laravel.com/docs/testing
- PHPUnit 12 — https://phpunit.de/documentation.html
- Filament Testing — https://filamentphp.com/docs/testing
- Spatie Permission Testing — https://spatie.be/docs/laravel-permission/basic-usage/testing

### Internal
- `MasterPlan.md` — spesifikasi proyek
- `AGENTS.md` — konvensi project (WAJIB dibaca ulang)
- `docs/data-dictionary.md` — peta skema DB
- `docs/runbook.md` — prosedur operasional

### Referensi Kode
- `tests/Feature/ScheduleRegistrationTest.php` — contoh **test perilaku**, bukan output CLI
- `tests/Feature/AdminMultiFactorAuthenticationTest.php` — contoh **test guard** konfigurasi
- `tests/Feature/DeactivateExpiredVipsCommandTest.php` — contoh test **race condition** (via `DB::listen`)
- `tests/Feature/SecurityHeadersTest.php` — contoh `#[DataProvider]` untuk input berbahaya

---

## 10. Anti-Pattern — Jangan Lakukan

| ❌ Jangan | ✅ Lakukan |
|-----------|------------|
| `php artisan test` | `vendor/bin/sail artisan test` |
| Tambah Pest / Xdebug ke composer | Pakai PHPUnit + `SAIL_XDEBUG_MODE` |
| `DB::listen` untuk test biasa | `RefreshDatabase` / `DatabaseTransactions` |
| Assertion pada output `schedule:list` | Assertion pada objek `Schedule` |
| Duplikasi test yang sudah ada | Baca test existing dulu |
| `markTestSkipped()` saat gagal | Perbaiki penyebabnya |
| MCP point ke `backbone_client` | Arahkan ke `dash_app` saja |
| Edit `~/.config/opencode` | Edit `dashboard-karangasem/.mcp.json` |
| Hardcode secret/dashboard ID di test | Pakai `config([...])` di `setUp()` |
| Test yang bergantung pada urutan jalan | Tiap test mandiri & deterministik |

---

**Siap dieksekusi.** Satu PR per TASK, pesan commit jelas, `pint` + `test` hijau sebelum merge.