<?php

namespace Tests\Feature;

use App\Services\PiiScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityScanPiiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'metabase.site_url' => 'https://metabase.test',
            'metabase.public_dashboard_id' => 2,
            'metabase.vip_dashboard_id' => 4,
            'metabase.admin_email' => 'admin@example.com',
            'metabase.admin_password' => 'rahasia-admin',
        ]);
    }

    /**
     * Respons kartu native berisis query SQL tertentu.
     */
    protected function nativeCard(int $id, string $name, string $sql): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'dataset_query' => [
                'type' => 'native',
                'native' => ['query' => $sql],
            ],
        ];
    }

    public function test_native_query_with_pii_column_fails_the_scan(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['id' => 'session-rahasia']),
            '*/api/dashboard/2' => Http::response([
                'dashcards' => [[
                    'card' => $this->nativeCard(10, 'Rincian Peserta Didik', 'SELECT nama, nisn FROM peserta_didik'),
                ]],
            ]),
            '*/api/dashboard/4' => Http::response(['dashcards' => []]),
        ]);

        $this->artisan('security:scan-pii')
            ->expectsOutputToContain('memuat kolom PII')
            ->assertFailed();
    }

    public function test_clean_dashboard_passes_the_scan(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['id' => 'session-rahasia']),
            '*/api/dashboard/2' => Http::response([
                'dashcards' => [[
                    'card' => $this->nativeCard(10, 'Jumlah Siswa per Kecamatan', 'SELECT kecamatan, COUNT(*) AS jumlah FROM metrics.v_peserta_didik GROUP BY kecamatan'),
                ]],
            ]),
            '*/api/dashboard/4' => Http::response([
                'dashcards' => [[
                    'card' => $this->nativeCard(11, 'Rasio Guru Murid', 'SELECT kecamatan, COUNT(*) FROM metrics.v_ptk GROUP BY kecamatan'),
                ]],
            ]),
        ]);

        $this->artisan('security:scan-pii')
            ->expectsOutput('Tidak ada kolom PII terdeteksi pada dashboard yang dipindai.')
            ->assertSuccessful();
    }

    public function test_mbql_cards_are_checked_through_field_metadata(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['id' => 'session-rahasia']),
            '*/api/dashboard/2' => Http::response([
                'dashcards' => [[
                    'card' => [
                        'id' => 20,
                        'name' => 'Kartu MBQL',
                        'dataset_query' => [
                            'type' => 'query',
                            'query' => ['source-table' => 7],
                        ],
                    ],
                ]],
            ]),
            '*/api/dashboard/4' => Http::response(['dashcards' => []]),
            '*/api/table/7/query_metadata' => Http::response([
                'fields' => [
                    ['name' => 'kecamatan'],
                    ['name' => 'nik'],
                    ['name' => 'jumlah_peserta_didik'],
                ],
            ]),
        ]);

        $this->artisan('security:scan-pii')->assertFailed();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/table/7/query_metadata'));
    }

    /**
     * `result_metadata` persis berisi kolom yang tampil di dashboard. Kalau
     * tersedia, metadata itu harus dipakai dan tabel sumber tidak boleh
     * dipindai seluruhnya — kalau tidak, setiap kartu agregat ikut reporting
     * hanya karena tabel sumbernya punya kolom PII.
     */
    public function test_mbql_cards_prefer_result_metadata_over_whole_table_metadata(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['id' => 'session-rahasia']),
            '*/api/dashboard/2' => Http::response([
                'dashcards' => [[
                    'card' => [
                        'id' => 21,
                        'name' => 'Jumlah Siswa per Kecamatan',
                        'result_metadata' => [
                            ['name' => 'kecamatan'],
                            ['name' => 'jumlah_peserta_didik'],
                        ],
                        'dataset_query' => [
                            'type' => 'query',
                            // Tabel sumber memuat nik, padahal tidak ditampilkan.
                            'query' => ['source-table' => 7],
                        ],
                    ],
                ]],
            ]),
            '*/api/dashboard/4' => Http::response(['dashcards' => []]),
            '*/api/table/7/query_metadata' => Http::response([
                'fields' => [
                    ['name' => 'kecamatan'],
                    ['name' => 'nik'],
                ],
            ]),
        ]);

        $this->artisan('security:scan-pii')
            ->expectsOutput('Tidak ada kolom PII terdeteksi pada dashboard yang dipindai.')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/table/7/query_metadata'));
    }

    public function test_mbql_cards_flag_pii_present_in_result_metadata(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['id' => 'session-rahasia']),
            '*/api/dashboard/2' => Http::response([
                'dashcards' => [[
                    'card' => [
                        'id' => 22,
                        'name' => 'Rincian Peserta Didik',
                        'result_metadata' => [['name' => 'nama'], ['name' => 'nisn']],
                        'dataset_query' => ['type' => 'query', 'query' => ['source-table' => 7]],
                    ],
                ]],
            ]),
            '*/api/dashboard/4' => Http::response(['dashcards' => []]),
        ]);

        $this->artisan('security:scan-pii')
            ->expectsOutputToContain('memuat kolom PII')
            ->assertFailed();
    }

    /**
     * Sebagian versi Metabase mengirim `source-table` sebagai string numerik.
     * Hanya menerima int membuat kartu tersebut lolos tanpa diperiksa.
     */
    public function test_mbql_cards_accept_a_numeric_string_source_table(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['id' => 'session-rahasia']),
            '*/api/dashboard/2' => Http::response([
                'dashcards' => [[
                    'card' => [
                        'id' => 23,
                        'name' => 'Kartu MBQL String',
                        'dataset_query' => ['type' => 'query', 'query' => ['source-table' => '7']],
                    ],
                ]],
            ]),
            '*/api/dashboard/4' => Http::response(['dashcards' => []]),
            '*/api/table/7/query_metadata' => Http::response([
                'fields' => [['name' => 'nik']],
            ]),
        ]);

        $this->artisan('security:scan-pii')
            ->expectsOutputToContain('memuat kolom PII')
            ->assertFailed();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/table/7/query_metadata'));
    }

    /**
     * Sumber query yang bukan tabel tidak bisa diperiksa otomatis. Titik
     * buta harus dilaporkan, bukan terlihat seperti "bersih".
     */
    public function test_uninspectable_query_sources_are_reported_as_a_warning(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['id' => 'session-rahasia']),
            '*/api/dashboard/2' => Http::response([
                'dashcards' => [[
                    'card' => [
                        'id' => 24,
                        'name' => 'Kartu Sumber Bertumpuk',
                        'dataset_query' => ['type' => 'query', 'query' => ['source-table' => 'card__12']],
                    ],
                ]],
            ]),
            '*/api/dashboard/4' => Http::response(['dashcards' => []]),
        ]);

        $this->artisan('security:scan-pii')
            ->expectsOutputToContain('tidak dapat dipindai otomatis')
            ->assertSuccessful();
    }

    public function test_unreadable_table_metadata_warns_instead_of_looking_clean(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['id' => 'session-rahasia']),
            '*/api/dashboard/2' => Http::response([
                'dashcards' => [[
                    'card' => [
                        'id' => 25,
                        'name' => 'Kartu Metadata Gagal',
                        'dataset_query' => ['type' => 'query', 'query' => ['source-table' => 7]],
                    ],
                ]],
            ]),
            '*/api/dashboard/4' => Http::response(['dashcards' => []]),
            '*/api/table/7/query_metadata' => Http::response(['error' => 'no access'], 403),
        ]);

        $this->artisan('security:scan-pii')
            ->expectsOutputToContain('Metadata tabel 7 tidak dapat dibaca')
            ->assertSuccessful();
    }

    public function test_unreachable_metabase_fails_without_leaking_credentials(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 6: Could not resolve host: metabase.test');
        });

        $this->artisan('security:scan-pii')
            ->expectsOutputToContain('Metabase tidak dapat dihubungi')
            ->assertFailed();

        $secret = config('metabase.admin_password');

        $this->assertNotSame('', $secret);
    }

    public function test_login_failure_is_reported_clearly(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['errors' => ['password' => 'did not match stored password']], 401),
        ]);

        $this->artisan('security:scan-pii')
            ->expectsOutputToContain('Login admin Metabase ditolak (status 401)')
            ->assertFailed();
    }

    public function test_missing_credentials_are_reported_before_any_request(): void
    {
        config(['metabase.admin_password' => null]);

        Http::preventStrayRequests();

        $this->artisan('security:scan-pii')
            ->expectsOutputToContain('METABASE_ADMIN_EMAIL dan METABASE_ADMIN_PASSWORD wajib diisi')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_dashboard_option_limits_the_scan_target(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['id' => 'session-rahasia']),
            '*/api/dashboard/9' => Http::response(['dashcards' => []]),
        ]);

        $this->artisan('security:scan-pii --dashboard=9')
            ->expectsOutput('Tidak ada kolom PII terdeteksi pada dashboard yang dipindai.')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/dashboard/9'));
    }

    public function test_report_file_is_written(): void
    {
        Http::fake([
            '*/api/session' => Http::response(['id' => 'session-rahasia']),
            '*/api/dashboard/2' => Http::response(['dashcards' => []]),
            '*/api/dashboard/4' => Http::response(['dashcards' => []]),
        ]);

        $this->artisan('security:scan-pii')->assertSuccessful();

        $path = storage_path('app/security/pii-scan-'.now()->format('Y-m-d').'.json');

        $this->assertFileExists($path);

        $report = json_decode((string) file_get_contents($path), true);

        $this->assertSame(0, $report['finding_count']);
        $this->assertContains('nama', $report['pii_columns_checked']);
        // Kredensial dan session id tidak boleh pernah masuk laporan.
        $this->assertStringNotContainsString('session-rahasia', (string) file_get_contents($path));
        $this->assertStringNotContainsString('rahasia-admin', (string) file_get_contents($path));
    }

    /**
     * @dataProvider aggregateColumnsThatMustNotBeFlagged
     */
    #[DataProvider('aggregateColumnsThatMustNotBeFlagged')]
    public function test_scanner_ignores_columns_that_merely_contain_a_pii_word(string $sql): void
    {
        $this->assertSame([], app(PiiScanner::class)->findInSql($sql));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function aggregateColumnsThatMustNotBeFlagged(): array
    {
        return [
            'nama_kecamatan' => ['SELECT nama_kecamatan, COUNT(*) FROM metrics.v_peserta_didik GROUP BY nama_kecamatan'],
            'nama_sekolah' => ['SELECT nama_sekolah, npsn FROM dbo.sekolah'],
            'nama_bentuk_pendidikan' => ['SELECT nama_bentuk_pendidikan FROM ref.bentuk_pendidikan'],
            'tempat_lahir_kabupaten' => ['SELECT tempat_lahir_kabupaten FROM ref.wilayah'],
            'alamat_ip tidak ada' => ['SELECT COUNT(*) AS jumlah FROM metrics.v_ptk'],
            'nama pada komentar' => ['SELECT kecamatan FROM metrics.v_peserta_didik -- nama sengaja tidak diambil'],
            'nik pada komentar blok' => ['SELECT kecamatan /* nik tidak ditampilkan */ FROM metrics.v_peserta_didik'],
            'nama pada string literal' => ["SELECT label FROM ref.jenis WHERE label = 'nama'"],
            'nik pada string literal' => ["SELECT COUNT(*) FROM ref.x WHERE keterangan <> 'nik'"],
        ];
    }

    /**
     * String literal berescape ganda harus ikut dibuang utuh.
     */
    public function test_scanner_handles_escaped_quotes_inside_string_literals(): void
    {
        $this->assertSame([], app(PiiScanner::class)->findInSql(
            "SELECT kecamatan FROM metrics.v_peserta_didik WHERE catatan = 'bukan ''nik'''"
        ));
    }

    /**
     * Identifier berutip dan huruf besar tetap terdeteksi setelah
     * penyaringan komentar dan literal.
     */
    public function test_scanner_flags_quoted_and_uppercase_identifiers(): void
    {
        $this->assertSame(['nama'], app(PiiScanner::class)->findInSql('SELECT "Nama" FROM dbo.peserta_didik'));
        $this->assertSame(['nik'], app(PiiScanner::class)->findInSql('select NIK from dbo.peserta_didik'));
    }

    /**
     * Nama panjang yang memuat nama kolom di dalamnya bukan kolom PII.
     */
    public function test_scanner_does_not_match_longer_identifiers(): void
    {
        $scanner = app(PiiScanner::class);

        $this->assertSame([], $scanner->findInSql('SELECT nama_orang, nisn_lokal FROM dbo.peserta_didik'));
        $this->assertSame([], $scanner->findInSql('SELECT namapenuh FROM dbo.peserta_didik'));
    }

    /**
     * @dataProvider piiColumns
     */
    #[DataProvider('piiColumns')]
    public function test_scanner_flags_individual_identity_columns(string $sql, string $expected): void
    {
        $this->assertSame([$expected], app(PiiScanner::class)->findInSql($sql));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function piiColumns(): array
    {
        return [
            'nik' => ['SELECT nik, npsn FROM dbo.peserta_didik', 'nik'],
            'nisn' => ['SELECT nisn FROM dbo.peserta_didik', 'nisn'],
            'no_kk' => ['SELECT no_kk FROM dbo.ats', 'no_kk'],
            'nuptk' => ['SELECT nuptk FROM dbo.ptk', 'nuptk'],
            'nama utuh' => ['SELECT nama FROM dbo.peserta_didik', 'nama'],
            'alamat' => ['SELECT alamat FROM dbo.peserta_didik', 'alamat'],
        ];
    }
}
