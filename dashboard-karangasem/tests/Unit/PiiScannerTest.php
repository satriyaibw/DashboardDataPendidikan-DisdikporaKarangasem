<?php

namespace Tests\Unit;

use App\Services\PiiScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aturan pencocokan `PiiScanner` diuji langsung pada metodenya.
 *
 * `SecurityScanPiiTest` sudah membuktikan scanner bekerja dari sisi perintah
 * Artisan (termasuk penyaringan komentar dan string literal). Test di sini
 * tidak mengulanginya; yang belum tersentuh adalah `findInFieldNames()` —
 * jalur metadata MBQL yang menerima bentuk input berbeda.
 */
class PiiScannerTest extends TestCase
{
    protected function scanner(): PiiScanner
    {
        return new PiiScanner;
    }

    public function test_it_detects_a_pii_column_in_a_native_query(): void
    {
        $this->assertSame(['nama'], $this->scanner()->findInSql('SELECT nama FROM dbo.peserta_didik'));
    }

    public function test_it_accepts_a_query_that_touches_only_aggregate_columns(): void
    {
        // Query bersih harus tetap lulus:false positive membuat dashboard sah
        // ditolak tanpa alasan yang bisa ditindaklanjuti.
        $this->assertSame([], $this->scanner()->findInSql(
            'SELECT nama_kecamatan, COUNT(*) FROM metrics.v_peserta_didik GROUP BY nama_kecamatan'
        ));
    }

    /**
     * @param  array<int, mixed>  $fields
     */
    #[DataProvider('identityColumns')]
    public function test_it_detects_each_declared_identity_column(array $fields, array $expected): void
    {
        $this->assertSame($expected, $this->scanner()->findInFieldNames($fields));
    }

    /**
     * @return array<string, array{0: array<int, mixed>, 1: array<int, string>}>
     */
    public static function identityColumns(): array
    {
        return [
            'nik' => [[['name' => 'nik'], ['name' => 'npsn']], ['nik']],
            'nisn' => [[['name' => 'nisn']], ['nisn']],
            'no_kk' => [[['name' => 'no_kk']], ['no_kk']],
            'nuptk' => [[['name' => 'nuptk']], ['nuptk']],
            'nama' => [[['name' => 'nama']], ['nama']],
            'alamat' => [[['name' => 'alamat']], ['alamat']],
            'huruf besar' => [[['name' => 'NIK']], ['nik']],
            'duplikat reported sekali' => [[['name' => 'nik'], ['name' => 'nik']], ['nik']],
            'hasil terurut' => [[['name' => 'nuptk'], ['name' => 'nik']], ['nik', 'nuptk']],
        ];
    }

    /**
     * Nama field Metabase adalah nama kolom apa adanya, bukan potongan SQL:
     * tidak ada penyaringan komentar atau string literal yang bisa dipakai.
     * Karena itu `nama_sekolah` TIDAK boleh dianggap sebagai kolom `nama` —
     * perbandingannya eksak, bukansubstring.
     */
    #[DataProvider('aggregateFieldsThatMustNotBeFlagged')]
    public function test_it_does_not_flag_aggregate_fields_that_merely_contain_a_pii_word(array $fields): void
    {
        $this->assertSame([], $this->scanner()->findInFieldNames($fields));
    }

    /**
     * @return array<string, array{0: array<int, mixed>}>
     */
    public static function aggregateFieldsThatMustNotBeFlagged(): array
    {
        return [
            'nama_kecamatan' => [[['name' => 'nama_kecamatan']]],
            'nama_sekolah' => [[['name' => 'nama_sekolah']]],
            'nisn_lokal' => [[['name' => 'nisn_lokal']]],
            'alamat_ip' => [[['name' => 'alamat_ip']]],
            'nama utuh sebagai bagian' => [[['name' => 'nama_peserta']]],
        ];
    }

    /**
     * Metadata Metabase tidak selalu rapi: field bisa datang sebagai string
     * polos, sebagai array tanpa kunci `name`, atau dengan nama kosong. Bentuk
     * seperti itu harus dilewati diam-diam, bukan memicu error.
     */
    #[DataProvider('unusableFieldShapes')]
    public function test_it_skips_field_entries_it_cannot_interpret(array $fields): void
    {
        $this->assertSame([], $this->scanner()->findInFieldNames($fields));
    }

    /**
     * @return array<string, array{0: array<int, mixed>}>
     */
    public static function unusableFieldShapes(): array
    {
        return [
            'tanpa kunci name' => [[['display_name' => 'nik']]],
            'nama kosong' => [[['name' => '']]],
            'nama bukan string' => [[['name' => 123]]],
            'nama null' => [[['name' => null]]],
            'daftar kosong' => [[]],
        ];
    }

    /**
     * Field string polos ikut diterima: sebagian endpoint Metabase
     * menyederhanakan metadata menjadi daftar nama.
     */
    public function test_it_accepts_plain_string_field_names(): void
    {
        $this->assertSame(['nik'], $this->scanner()->findInFieldNames(['nik', 'npsn']));
    }
}
