-- ============================================================================
-- Fase 2 — View PII untuk VIP (MasterPlan §6.3, Issue #3)
--
-- View ini berisi data PII (nama, NISN, NIK, dll) yang HANYA bisa diakses
-- melalui dashboard VIP. View ini dibuat khusus untuk role `analis` sehingga
-- user VIP bisa melihat PII tanpa perlu query native.
--
-- PENTING: View ini HANYA untuk agregat/filter, bukan untuk export massal.
-- Dashboard VIP harus dikonfigurasi dengan filter yang sesuai.
--
-- Cara pakai:
--   psql -h <host> -U <superuser> -d backbone_client -f metrics_pii_views.sql
-- ============================================================================

-- Pastikan schema metrics ada
CREATE SCHEMA IF NOT EXISTS metrics;

-- ----------------------------------------------------------------------------
-- View: Detail Peserta Didik (PII)
-- Hanya untuk dashboard VIP, dengan filter yang sesuai
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW metrics.v_peserta_didik_detail AS
SELECT
    a.semester_id::text                        AS semester_id,
    a.nama::text                               AS nama,
    a.nisn::text                               AS nisn,
    a.nik::text                                AS nik,
    a.no_kk::text                              AS no_kk,
    a.nama_ibu_kandung::text                   AS nama_ibu_kandung,
    a.jenis_kelamin::text                      AS jenis_kelamin,
    a.tempat_lahir::text                       AS tempat_lahir,
    a.tanggal_lahir::text                       AS tanggal_lahir,
    a.alamat_jalan::text                       AS alamat_jalan,
    a.sekolah_id::text                         AS sekolah_id,
    s.nama::text                               AS nama_sekolah,
    btrim(a.sekolah_kode_kecamatan)::text      AS kode_kecamatan,
    w.nama::text                               AS kecamatan,
    a.jenjang_pendidikan::text                 AS jenjang
FROM dbo.ats a
JOIN dbo.sekolah s ON s.sekolah_id = a.sekolah_id
JOIN ref.mst_wilayah w
  ON w.kode_wilayah = btrim(a.sekolah_kode_kecamatan)
 AND w.id_level_wilayah = 3
WHERE a.aktif = 1
  AND a.soft_delete = 0
  AND w.kode_wilayah LIKE '2208%';  -- Kabupaten Karangasem

GRANT SELECT ON metrics.v_peserta_didik_detail TO analis;

-- ----------------------------------------------------------------------------
-- View: Detail PTK (PII)
-- Hanya untuk dashboard VIP, dengan filter yang sesuai
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW metrics.v_ptk_detail AS
SELECT
    p.tahun_ajaran_id::text                    AS tahun_ajaran_id,
    ptk.nama::text                             AS nama,
    ptk.nik::text                              AS nik,
    ptk.nuptk::text                            AS nuptk,
    p.sekolah_id::text                         AS sekolah_id,
    s.nama::text                               AS nama_sekolah,
    btrim(substring(s.kode_wilayah FROM 1 FOR 6))::text AS kode_kecamatan,
    w.nama::text                               AS kecamatan,
    p.jenis_ptk_id::text                       AS jenis_ptk_id
FROM dbo.ptk_terdaftar p
JOIN dbo.ptk ptk ON ptk.ptk_id = p.ptk_id
JOIN dbo.sekolah s ON s.sekolah_id = p.sekolah_id
JOIN ref.mst_wilayah w
  ON w.kode_wilayah = btrim(substring(s.kode_wilayah FROM 1 FOR 6))
 AND w.id_level_wilayah = 3
WHERE p."Soft_delete" = 0
  AND w.kode_wilayah LIKE '2208%';  -- Kabupaten Karangasem

GRANT SELECT ON metrics.v_ptk_detail TO analis;

-- ----------------------------------------------------------------------------
-- Hak akses untuk role `analis` pada view PII
-- ----------------------------------------------------------------------------
GRANT USAGE ON SCHEMA metrics TO analis;
GRANT SELECT ON ALL TABLES IN SCHEMA metrics TO analis;
