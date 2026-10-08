-- ============================================================================
-- Fase 2 — Role `admin_full` (Admin Metabase)
--
-- Admin: full view, read-only (no DELETE/INSERT/UPDATE/DDL)
-- - Bisa query native ke semua schema (metrics, dbo, ref, datamart)
-- - Bisa lihat semua data termasuk PII
-- - TIDAK BISA modify data (read-only)
--
-- Cara pakai:
--   psql -h <host> -U <superuser> -d backbone_client -f admin_full_role.sql
-- ============================================================================

-- Buat role jika belum ada
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'admin_full') THEN
        CREATE ROLE admin_full;
    END IF;
END
$$;

-- Grant koneksi ke database
GRANT CONNECT ON DATABASE backbone_client TO admin_full;

-- Grant usage pada schema
GRANT USAGE ON SCHEMA metrics, dbo, ref, datamart TO admin_full;

-- Grant SELECT pada semua tabel di schema tersebut
GRANT SELECT ON ALL TABLES IN SCHEMA metrics, dbo, ref, datamart TO admin_full;

-- Grant SELECT untuk tabel yang dibuat di masa depan oleh role pembuat objek
-- (sesuaikan FOR ROLE dengan owner/ETL yang membuat tabel, mis. 'etl' atau 'postgres')
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA metrics, dbo, ref, datamart GRANT SELECT ON TABLES TO admin_full;

-- Catatan: TIDAK ada GRANT untuk INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER
-- Role ini strictly read-only
