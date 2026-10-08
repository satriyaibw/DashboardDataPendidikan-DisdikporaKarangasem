# Prosedur Migrasi View `metrics` ke `datamart`

> `datamart` adalah agregat per sekolah/semester berbentuk **wide**
> (`pd_tkt_1_l`, `pd_tkt_1_p`, `ptk_guru_s1_l`, `rombel_1`, dst.;
> `_l` = laki-laki, `_p` = perempuan). Kontrak `metrics.*` berbentuk **long**
> dan kolomnya TIDAK boleh berubah — inilah yang membuat dashboard Metabase
> tidak perlu diubah saat cutover.

## Gerbang Cutover (WAJIB, jalankan dulu)

1. **Pastikan `datamart` sudah terisi** oleh job Pusdatin (`count(*) > 0`
   pada tabel utama, mis. `datamart.sekolah_peserta_didik_tingkat`).
2. **Jalankan skrip kesetaraan angka** (bandingkan hasil view dari `dbo`
   vs view sementara berbasis `datamart`). Hanya lanjut jika selisih = 0
   untuk semua metrik yang diperbandingkan.
3. Jika valid → `CREATE OR REPLACE VIEW metrics.v_*` dengan sumber
   `datamart` (unpivot). Kontrak kolom **tidak berubah**.

## Contoh Unpivot (wide → long) — peserta didik per tingkat

Sebelum (sumber `dbo`): dihitung di `database/sql/metrics_views.sql`.
Sesudah (sumber `datamart`), pola unpivot:

```sql
CREATE OR REPLACE VIEW metrics.v_peserta_didik AS
SELECT semester_id::text, kode_kecamatan::text, kecamatan::text,
       'SD / sederajat' AS jenjang, jk AS jenis_kelamin, jumlah
FROM (
  SELECT semester_id, kode_kecamatan, kecamatan, 'L' AS jk, pd_tkt_1_l AS jumlah
    FROM datamart.sekolah_peserta_didik_tingkat
  UNION ALL
  SELECT semester_id, kode_kecamatan, kecamatan, 'P', pd_tkt_1_p
    FROM datamart.sekolah_peserta_didik_tingkat
  -- … pasangan (l,p) per level lainnya …
) t
WHERE kode_kecamatan LIKE '2208%';
```

PostgreSQL 16 tidak punya `UNPIVOT` native; gunakan `UNION ALL` (eksplisit,
paling kompatibel), `jsonb_each`/`VALUES` lateral, atau `crosstab`
(`tablefunc`). Rekomendasi: **`UNION ALL`** — deterministik, mudah di-review.

## Skrip Uji Kesetaraan Angka

`database/sql/test_equivalence.sql` — membandingkan total per dimensi kunci
antara view berbasis `dbo` dan view berbasis `datamart`. Jalankan
sebelum cutover; hasil harus 0 baris (tidak ada selisih).

## Checklist Cutover

- [ ] `count(*) > 0` di tabel utama `datamart`
- [ ] `database/sql/test_equivalence.sql` → 0 baris selisih
- [ ] `CREATE OR REPLACE VIEW metrics.v_*` dari `datamart`
- [ ] Ulangi uji read-only `analis` (`docs/read-only-test.md`)
- [ ] Spot-check dashboard Metabase (angka tidak berubah vs sebelum cutover)
