<?php

namespace App\Services;

/**
 * Deteksi kolom PII pada query dashboard Metabase.
 *
 * Pencocokan sengaja berbasis **batas kata** (identifier-aware), bukan
 * pencarian substring: mencari "nama" secara naif akan menandai
 * `nama_kecamatan`, `nama_sekolah`, `nama_bentuk_pendidikan` — semuanya kolom
 * agregat yang sah. Dengan \b...\b, `nama` hanya cocok apabila merupakan
 * identifier utuh.
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
     * Cari kolom PII pada teks query SQL native.
     *
     * @return array<int, string> Nama kolom PII yang ditemukan, unik & terurut.
     */
    public function findInSql(string $sql): array
    {
        $found = [];

        foreach (self::PII_COLUMNS as $column) {
            // \b memastikan match sebagai identifier utuh: 'nama' tidak akan
            // cocok pada 'nama_kecamatan' karena underscore adalah karakter kata.
            if (preg_match('/\b'.$this->quoteColumn($column).'\b/i', $sql) === 1) {
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
                if (strcasecmp($name, $column) === 0) {
                    $found[] = $column;
                }
            }
        }

        return $this->unique($found);
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
