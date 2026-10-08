# Data Dictionary — `backbone_client`

> Sumber kebenaran: inspeksi nyata ke instance PostgreSQL 16.15 (dump
> `backbone_client_20261008_082345.dump`, PGDMP v1.15-0).
> Temuan konsisten dengan `MasterPlan.md` Lampiran A, dengan koreksi/detail kolom di bawah.

## 1. Gambaran Besar

| Schema | Isi | Jumlah tabel | Dipakai dashboard? |
|---|---|---|---|
| `metrics` | **Lapisan kontrak buatan kita** (view, bentuk long, kolom stabil) | 3 view | **Ya — satu-satunya yang dilihat Metabase** |
| `datamart` | Agregat per sekolah/semester (struktur ada, **0 baris**) | 22 | Ya, setelah terisi (via unpivot ke `metrics`) |
| `dbo` | Data mentah sumber sinkronisasi backbone | 24 | Tidak langsung — hanya sumber view `metrics` |
| `ref` | Lookup/referensi (wilayah, agama, jenjang, …) | 102 | Ya, sebagai lookup join |
| `qc` | Validasi kualitas data | — | Tidak |
| `sync` | Log & checkpoint sinkronisasi | — | Tidak |

Fakta teknis: 130 PRIMARY KEY, 0 foreign key (integritas aplikatif), tidak ada
index eksplisit selain PK, dump PostgreSQL 16.15.

## 2. Ringkasan Isi `dbo` (jumlah baris nyata)

| Tabel | Baris | Makna |
|---|---|---|
| `sekolah` | 843 | Satuan pendidikan (wilayah `kode_wilayah` BPS-style, mis. `220801...`) |
| `peserta_didik` | 80.311 | Identitas peserta didik (nama, nisn, nik) — **PII** |
| `peserta_didik_longitudinal` | 69.118 | Atribut PD per semester (TB/BB, jarak sekolah, dll); PK (`peserta_didik_id`,`semester_id`) |
| `ats` | 5.944 | Data PD per semester + dimensi (jenjang, jenis_kelamin, kecamatan sekolah, `aktif`, `soft_delete`) — dipakai view `metrics.v_peserta_didik` |
| `registrasi_peserta_didik` | 71.051 | Riwayat registrasi PD per sekolah |
| `ptk` | 8.012 | Identitas PTK (nama, nik, nuptk) — **PII** |
| `ptk_terdaftar` | 5.257 | Penempatan PTK per sekolah/tahun ajaran, jabatan, `aktif_bulan_xx` |
| `rombongan_belajar` | 5.077 | Rombel per semester (tingkat, jurusan, wali kelas `ptk_id`) |
| `anggota_rombel` | 97.758 | Keanggotaan PD di rombel (`Soft_delete`) |
| `alat` | 109.971 | Sarana/prasarana per sekolah |
| `bangunan` | 4.166 | Bangunan sekolah |
| `ruang` | 11.449 | Ruang belajar/dll |
| `buku` | 29.065 | Koleksi buku |
| `sanitasi` | 13.537 | Fasilitas sanitasi |
| `tanah` / `ruang_longitudinal` / `pembelajaran` / `jaringan`... | 671 / 1.773 / 18.039 | … |
| `akreditasi_sp` / `akreditasi_prodi` | 1.465 / 50 | Akreditasi |
| `ats` (kolom PII) | `nik`, `no_kk`, `nama`, … | **JANGAN ditampilkan dashboard** |

> Catatan: `dbo.peserta_didik` 80.311 baris adalah master identitas; data
> agregat per semester baru tersedia lewat `dbo.ats` (5.944) dan
> `dbo.rombongan_belajar`/`dbo.anggota_rombel`. Hanya 390 baris `ats` aktif
> yang berkode wilayah Karangasem (`2208%`) — angka agregat `metrics`
> mengikuti kondisi nyata ini dan harus dibaca apa adanya, bukan memaksakan
> angka referensi MasterPlan.

## 3. Kolom Kunci per Tabel (hasil verifikasi)

### `dbo.ats` — sumber `metrics.v_peserta_didik`
| Kolom | Tipe | Makna |
|---|---|---|
| `semester_id` | varchar(5) | Semester (mis. `20222`) |
| `jenis_kelamin` | char(1) | `L` / `P` |
| `jenjang_pendidikan` | varchar(25) | Mis. `SD / sederajat`, `SMP / sederajat`, `Paket C` |
| `tingkat_pendidikan` | numeric | Kode tingkat |
| `sekolah_kode_kecamatan` | char(8) | Kode kecamatan sekolah (6 digit aktif, join ke `ref.mst_wilayah`) |
| `sekolah_kode_kabupaten` | char(8) | Label wilayah sekolah |
| `aktif`, `soft_delete`, `status` | numeric/varchar | Filter: `aktif=1 AND soft_delete=0` |
| `nik`, `no_kk`, `nama` | varchar | **PII — tidak diekspos ke `metrics`** |

### `dbo.rombongan_belajar`
| Kolom | Tipe | Makna |
|---|---|---|
| `semester_id` | char(5) | Semester |
| `sekolah_id` | varchar(36) | → `dbo.sekolah` |
| `tingkat_pendidikan_id` | numeric | Kode tingkat |
| `ptk_id` | varchar(36) | Wali kelas (nullable) |
| `nama` | varchar(30) | Nama rombel |
| `Soft_delete` | numeric | Filter `= 0` |

### `dbo.sekolah`
| Kolom | Tipe | Makna |
|---|---|---|
| `kode_wilayah` | char(8) | BPS-style; kecamatan = `substring(kode_wilayah,1,6)` |
| `npsn`, `nama`, `bentuk_pendidikan_id`, `status_sekolah` | … | Identitas sekolah |

### `ref.mst_wilayah`
| Kolom | Tipe | Makna |
|---|---|---|
| `kode_wilayah` | char(8) | PK; level 3 (kecamatan) mis. `220801` = Kec. Rendang |
| `id_level_wilayah` | smallint | 0 Indonesia … 4 Desa |
| `nama` | varchar(60) | Nama wilayah |

Kecamatan Karangasem (`kode_wilayah LIKE '2208%'`, 8 kecamatan): Rendang,
Sidemen, Manggis, Karangasem, Abang, Bebandem, Selat, Kubu.

### `dbo.ptk_terdaftar`
| Kolom | Tipe | Makna |
|---|---|---|
| `ptk_id`, `sekolah_id`, `tahun_ajaran_id` | … | Dimensi |
| `jenis_ptk_id`, `jabatan_ptk_id` | numeric | Jenis PTK/jabatan (kode → `ref`) |
| `aktif_bulan_01..12` | numeric(1) | Status aktif per bulan |
| `Soft_delete` | numeric | Filter `= 0` |

## 4. `datamart.*` — struktur siap, kosong
Semua 22 tabel bermuara pada pola: `sekolah_id, semester_id, npsn, nama,
bentuk_pendidikan, status_sekolah, kode_wilayah, provinsi/kabupaten/kecamatan`
+ metrik wide (`_l` = laki-laki, `_p` = perempuan, mis. `pd_tkt_1_l`,
`ptk_guru_s1_p`, `rombel_1`). **Semua 0 baris** (`COPY ... \.` langsung).
Saat terisi → unpivot ke kontrak long `metrics` (lihat `docs/migration-datamart.md`).

## 5. PII — Aturan
`dbo.peserta_didik` (nama, nisn, nik), `dbo.ats` (nik, no_kk, nama),
`dbo.ptk` (nik, nuptk), `dbo.registrasi_peserta_didik` **tidak boleh**
muncul di view `metrics` atau dashboard. `metrics` hanya berisi angka agregat
dan dimensi non-PII (semester, kecamatan, jenjang, jenis kelamin, jenis PTK).
