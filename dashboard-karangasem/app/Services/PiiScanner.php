<?php

namespace App\Services;

/**
 * Deteksi kolom PII pada query dashboard Metabase.
 *
 * Pencocokan sengaja berbasis **batas kata** (identifier-aware), bukan
 * pencarian substring: mencari "nama" secara naif akan menandai
 * `nama_kecamatan`, `nama_sekolah`, `nama_bentuk_pendidikan` — semuanya kolom
 * agregat yang sah. Dengan batas identifier eksplisit, `nama` hanya cocok
 * apabila merupakan identifier utuh.
 *
 * Batas ditulis sebagai lookaround `(?<![A-Za-z0-9_])` / `(?![A-Za-z0-9_])`
 * alih-alih `\b`: hasilnya sama untuk ASCII, tapi perilakunya tidak
 * bergantung pada pengaturan locale/Unicode PCRE, sehingga perilakunya sama
 * di semua mesin yang menjalankan perintah ini.
 */
class PiiScanner
{
    /**
     * Kolom yang dianggap identitas individu dan tidak boleh tampil.
     *
     * Sumber: MasterPlan §9 dan Lampiran A (`dbo.peserta_didik` [nama, nisn,
     * nik], `dbo.ats` [nik, no_kk, nama], `dbo.ptk` [nik, nuptk]).
     *
     * @var array<int, string>
     */
    public const PII_COLUMNS = [
        'nik',
        'nisn',
        'no_kk',
        'nuptk',
        'nama',
        'nama_lengkap',
        'alamat',
        'tempat_lahir',
        'tanggal_lahir',
        'no_hp',
        'telepon',
        'email',
    ];

    /**
     * Pola untuk membuang bagian SQL yang bukan rujukan kolom.
     *
     * String literal dan komentar sering memuat nama kolom secara kebetulan
     * (`-- kolom nik sengaja dikecualikan`, `WHERE label = 'alamat'`).
     * Bagian seperti itu dibuang sebelum pencocokan agar laporan tidak
     * dipenuhi temuan palsu yang justru membuat laporan tidak dipercaya.
     */
    private const NOISE_PATTERNS = [
        // Komentar baris: -- ... hingga akhir baris.
        '/--[^\r\n]*/',
        // Komentar blok: /* ... */ (non-greedy).
        '/\/\*.*?\*\//s',
        // String literal: '...' dengan escape '' di dalamnya.
        "/'(?:[^']|'')*'/",
    ];

    /**
     * Cari kolom PII pada teks query SQL native.
     *
     * @return array<int, string> Nama kolom PII yang ditemukan, unik & terurut.
     */
    public function findInSql(string $sql): array
    {
        $searchable = $this->withoutNoise($sql);

        $found = [];

        foreach (self::PII_COLUMNS as $column) {
            // Batas identifier eksplisit: 'nama' tidak akan cocok pada
            // 'nama_kecamatan' karena underscore termasuk karakter identifier,
            // dan tidak akan cocok pada 'namaxx'. Sebaliknya identifier yang
            // diapit tanda kutip ("nama") tetap terdeteksi.
            if (preg_match('/(?<![A-Za-z0-9_])'.$this->quoteColumn($column).'(?![A-Za-z0-9_])/i', $searchable) === 1) {
                $found[] = $column;
            }
        }

        return $this->unique($found);
    }

    /**
     * Cari kolom PII pada daftar nama field Metabase (hasil query_metadata).
     *
     * @param  array<int, mixed>  $fields
     * @return array<int, string>
     */
    public function findInFieldNames(array $fields): array
    {
        $found = [];

        foreach ($fields as $field) {
            $name = is_array($field) ? ($field['name'] ?? null) : $field;

            if (! is_string($name) || $name === '') {
                continue;
            }

            foreach (self::PII_COLUMNS as $column) {
                // Perbandingan nama field bersifat eksak: metadata Metabase
                // sudah memberi nama kolom apa adanya, bukan potongan SQL.
                if (strcasecmp($name, $column) === 0) {
                    $found[] = $column;
                }
            }
        }

        return $this->unique($found);
    }

    /**
     * Buang komentar dan string literal dari teks SQL.
     */
    protected function withoutNoise(string $sql): string
    {
        foreach (self::NOISE_PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, ' ', $sql);

            if (is_string($replaced)) {
                $sql = $replaced;
            }
        }

        return $sql;
    }

    protected function quoteColumn(string $column): string
    {
        return preg_quote($column, '/');
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    protected function unique(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $normalized[strtolower($value)] = strtolower($value);
        }

        $result = array_values($normalized);
        sort($result);

        return $result;
    }
}
