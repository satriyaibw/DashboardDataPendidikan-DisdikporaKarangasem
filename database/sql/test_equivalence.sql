-- ============================================================================
-- Fase 1 §5.4 — Skrip Uji Kesetaraan Angka (gerbang cutover ke datamart)
--
-- Bandingkan hasil agregasi view kontrak (sumber dbo) dengan agregasi
-- langsung dari datamart. SEBELUM cutover: datamart masih kosong, jadi skrip
-- akan melaporkan selisih — itu wajar. SETELAH job Pusdatin mengisi datamart
-- dan view metrics di-unpivot ke datamart, skrip ini HARUS mengembalikan
-- 0 baris (tidak ada selisih). Jika ada baris, JANGAN cutover.
-- ============================================================================

-- CATATAN PENTING: Sebelum cutover, isi placeholder datamart di bawah dengan
-- pemetaan jenjang nyata sesuai struktur datamart.sekolah_peserta_didik_tingkat.
-- Pola pd_tkt_N_l / pd_tkt_N_p mewakili tingkat N (l=laki, p=perempuan).
-- Pastikan SEMUA jenjang yang ada di metrics.v_peserta_didik terwakili agar
-- selisih 0 benar-benar berarti seluruh dimensi — bukan hanya SD.
WITH dari_dbo AS (
    SELECT kode_kecamatan, jenjang, jenis_kelamin, sum(jumlah) AS jumlah_dbo
    FROM metrics.v_peserta_didik
    GROUP BY 1, 2, 3
),
dari_datamart AS (
    -- placeholder: agregat datamart setara, unpivot (l/p) ke long
    -- DILUASKAN: cakup semua tingkat, bukan hanya SD
    SELECT kode_kecamatan::text AS kode_kecamatan,
           jenjang, jk::text AS jenis_kelamin,
           sum(jumlah) AS jumlah_datamart
    FROM (
        -- Tingkat 1 — SD / sederajat (kelas 1)
        SELECT kode_kecamatan, 'SD / sederajat' AS jenjang, 'L' AS jk, pd_tkt_1_l AS jumlah
          FROM datamart.sekolah_peserta_didik_tingkat
        UNION ALL
        SELECT kode_kecamatan, 'SD / sederajat', 'P', pd_tkt_1_p
          FROM datamart.sekolah_peserta_didik_tingkat
        -- Tingkat 2 — SD / sederajat (kelas 2)
        UNION ALL
        SELECT kode_kecamatan, 'SD / sederajat', 'L', pd_tkt_2_l
          FROM datamart.sekolah_peserta_didik_tingkat
        UNION ALL
        SELECT kode_kecamatan, 'SD / sederajat', 'P', pd_tkt_2_p
          FROM datamart.sekolah_peserta_didik_tingkat
        -- Tingkat 3 — SMP / sederajat (kelas 7)
        UNION ALL
        SELECT kode_kecamatan, 'SMP / sederajat', 'L', pd_tkt_3_l
          FROM datamart.sekolah_peserta_didik_tingkat
        UNION ALL
        SELECT kode_kecamatan, 'SMP / sederajat', 'P', pd_tkt_3_p
          FROM datamart.sekolah_peserta_didik_tingkat
        -- Tingkat 4 — SMP / sederajat (kelas 8)
        UNION ALL
        SELECT kode_kecamatan, 'SMP / sederajat', 'L', pd_tkt_4_l
          FROM datamart.sekolah_peserta_didik_tingkat
        UNION ALL
        SELECT kode_kecamatan, 'SMP / sederajat', 'P', pd_tkt_4_p
          FROM datamart.sekolah_peserta_didik_tingkat
        -- Tingkat 5 — SMA / sederajat (kelas 10)
        UNION ALL
        SELECT kode_kecamatan, 'SMA / sederajat', 'L', pd_tkt_5_l
          FROM datamart.sekolah_peserta_didik_tingkat
        UNION ALL
        SELECT kode_kecamatan, 'SMA / sederajat', 'P', pd_tkt_5_p
          FROM datamart.sekolah_peserta_didik_tingkat
        -- Tingkat 6 — SMA / sederajat (kelas 11)
        UNION ALL
        SELECT kode_kecamatan, 'SMA / sederajat', 'L', pd_tkt_6_l
          FROM datamart.sekolah_peserta_didik_tingkat
        UNION ALL
        SELECT kode_kecamatan, 'SMA / sederajat', 'P', pd_tkt_6_p
          FROM datamart.sekolah_peserta_didik_tingkat
        -- TODO: Tambah tingkat lainnya (Paket B, Paket C, SMK, dll) sesuai kolom nyata datamart
    ) t
    GROUP BY 1, 2, 3
)
SELECT
    COALESCE(d.kode_kecamatan, m.kode_kecamatan) AS kode_kecamatan,
    COALESCE(d.jenjang, m.jenjang)               AS jenjang,
    COALESCE(d.jenis_kelamin, m.jenis_kelamin)   AS jenis_kelamin,
    d.jumlah_dbo,
    m.jumlah_datamart,
    (d.jumlah_dbo - m.jumlah_datamart)           AS selisih
FROM dari_dbo d
FULL OUTER JOIN dari_datamart m
  ON m.kode_kecamatan = d.kode_kecamatan
 AND m.jenjang = d.jenjang
 AND m.jenis_kelamin = d.jenis_kelamin
WHERE d.jumlah_dbo IS DISTINCT FROM m.jumlah_datamart;
