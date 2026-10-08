-- ============================================================================
-- Fase 1 — Lapisan Kontrak `metrics` (WAJIB, §5.3)
--
-- Filosofi:
--   * Dashboard (Metabase) HANYA boleh mereferensikan schema `metrics`.
--   * Sumber saat ini: `dbo.*` + `ref.*` (karena `datamart` masih kosong).
--   * Saat `datamart` terisi, cukup CREATE OR REPLACE VIEW yang sama dari
--     `datamart.*` (unpivot wide -> long). Nama & tipe kolom TIDAK berubah.
--
-- Idempotent: aman dijalankan ulang (CREATE OR REPLACE VIEW).
-- ============================================================================

CREATE SCHEMA IF NOT EXISTS metrics;

-- ----------------------------------------------------------------------------
-- 1) Peserta didik aktif per semester x kecamatan x jenjang x jenis kelamin
--    Sumber: dbo.ats (data peserta didik per semester, sudah memuat nama
--    kecamatan sekolah via kolom denormalisasi), lookup wilayah ke
--    ref.mst_wilayah (level 3 = kecamatan).
--
--    Kontrak kolom (stabil):
--      semester_id    | text(5)
--      kode_kecamatan | text(6)
--      kecamatan      | text
--      jenjang        | text
--      jenis_kelamin  | text  -- 'L' / 'P'
--      jumlah         | bigint
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW metrics.v_peserta_didik AS
SELECT
    a.semester_id::text                        AS semester_id,
    btrim(a.sekolah_kode_kecamatan)::text      AS kode_kecamatan,
    w.nama::text                               AS kecamatan,
    a.jenjang_pendidikan::text                 AS jenjang,
    a.jenis_kelamin::text                      AS jenis_kelamin,
    count(*)::bigint                           AS jumlah
FROM dbo.ats a
JOIN ref.mst_wilayah w
  ON w.kode_wilayah = btrim(a.sekolah_kode_kecamatan)
 AND w.id_level_wilayah = 3
WHERE a.aktif = 1
  AND a.soft_delete = 0
  AND w.kode_wilayah LIKE '2208%'            -- Kabupaten Karangasem (hardcode — fokus Kab. Karangasem)
GROUP BY 1, 2, 3, 4, 5;

GRANT SELECT ON metrics.v_peserta_didik TO analis;

-- ----------------------------------------------------------------------------
-- 2) Rombongan belajar & anggotanya per semester x kecamatan x tingkat
--      semester_id            | text(5)
--      kode_kecamatan         | text(6)
--      kecamatan              | text
--      tingkat_pendidikan_id  | numeric
--      jumlah_rombel          | bigint
--      jumlah_anggota         | bigint
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW metrics.v_rombongan_belajar AS
SELECT
    r.semester_id::text                          AS semester_id,
    btrim(substring(s.kode_wilayah FROM 1 FOR 6))::text AS kode_kecamatan,
    w.nama::text                                 AS kecamatan,
    r.tingkat_pendidikan_id                      AS tingkat_pendidikan_id,
    count(DISTINCT r.rombongan_belajar_id)::bigint AS jumlah_rombel,
    count(a.anggota_rombel_id)::bigint           AS jumlah_anggota
FROM dbo.rombongan_belajar r
JOIN dbo.sekolah s
  ON s.sekolah_id = r.sekolah_id
JOIN ref.mst_wilayah w
  ON w.kode_wilayah = btrim(substring(s.kode_wilayah FROM 1 FOR 6))
 AND w.id_level_wilayah = 3
LEFT JOIN dbo.anggota_rombel a
  ON a.rombongan_belajar_id = r.rombongan_belajar_id
 AND a."Soft_delete" = 0
WHERE r."Soft_delete" = 0
  AND w.kode_wilayah LIKE '2208%'           -- Kabupaten Karangasem (hardcode — fokus Kab. Karangasem)
GROUP BY 1, 2, 3, 4;

GRANT SELECT ON metrics.v_rombongan_belajar TO analis;

-- ----------------------------------------------------------------------------
-- 3) PTK terdaftar per tahun ajaran x kecamatan x jenis PTK
--      tahun_ajaran_id | numeric
--      kode_kecamatan  | text(6)
--      kecamatan       | text
--      jenis_ptk_id    | numeric
--      jumlah          | bigint
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW metrics.v_ptk AS
SELECT
    p.tahun_ajaran_id                              AS tahun_ajaran_id,
    btrim(substring(s.kode_wilayah FROM 1 FOR 6))::text AS kode_kecamatan,
    w.nama::text                                   AS kecamatan,
    p.jenis_ptk_id                                 AS jenis_ptk_id,
    count(DISTINCT p.ptk_id)::bigint               AS jumlah  -- count penempatan (PTK dapat terdaftar di >1 sekolah)
FROM dbo.ptk_terdaftar p
JOIN dbo.sekolah s
  ON s.sekolah_id = p.sekolah_id
JOIN ref.mst_wilayah w
  ON w.kode_wilayah = btrim(substring(s.kode_wilayah FROM 1 FOR 6))
 AND w.id_level_wilayah = 3
WHERE p."Soft_delete" = 0
  AND w.kode_wilayah LIKE '2208%'            -- Kabupaten Karangasem (hardcode — fokus Kab. Karangasem)
GROUP BY 1, 2, 3, 4;

GRANT SELECT ON metrics.v_ptk TO analis;

-- ----------------------------------------------------------------------------
-- Hak akses read-only untuk role `analis` (Metabase) — lihat §5.3
-- ----------------------------------------------------------------------------
GRANT USAGE ON SCHEMA metrics TO analis;
GRANT SELECT ON ALL TABLES IN SCHEMA metrics TO analis;
-- Catatan: ALTER DEFAULT PRIVILEGES hanya berlaku untuk objek yang dibuat SETELAH
-- perintah ini dijalankan. View yang sudah ada ditangani oleh GRANT SELECT ON
-- ALL TABLES di atas. Perintah di bawah tetap dipertahankan untuk view baru
-- yang ditambahkan di masa depan.
ALTER DEFAULT PRIVILEGES IN SCHEMA metrics GRANT SELECT ON TABLES TO analis;
