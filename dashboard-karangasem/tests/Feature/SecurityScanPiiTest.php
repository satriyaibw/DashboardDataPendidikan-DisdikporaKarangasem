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
        ];
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
