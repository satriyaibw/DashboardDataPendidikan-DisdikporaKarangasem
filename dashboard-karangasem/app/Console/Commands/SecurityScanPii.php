<?php

namespace App\Console\Commands;

use App\Services\MetabaseOrigin;
use App\Services\PiiScanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class SecurityScanPii extends Command
{
    protected $signature = 'security:scan-pii
        {--dashboard=* : Batasi pemindaian ke dashboard ID tertentu}';

    protected $description = 'Buktikan dashboard Publik/VIP tidak menampilkan kolom PII';

    /**
     * Jenis query Metabase yang dapat diperiksa otomatis.
     */
    private const QUERY_TYPE_NATIVE = 'native';

    private const QUERY_TYPE_MBQL = 'query';

    public function handle(PiiScanner $scanner): int
    {
        $siteUrl = rtrim((string) config('metabase.site_url'), '/');

        if ($siteUrl === '' || MetabaseOrigin::resolve(config('metabase.site_url')) === null) {
            $this->error('metabase.site_url tidak dikonfigurasi atau tidak valid. Isi METABASE_SITE_URL terlebih dahulu.');

            return self::FAILURE;
        }

        $targets = $this->dashboardTargets();

        if ($targets === []) {
            $this->error('Tidak ada dashboard yang dipindai. Setel METABASE_PUBLIC_DASHBOARD_ID dan/atau METABASE_VIP_DASHBOARD_ID.');

            return self::FAILURE;
        }

        $sessionId = $this->authenticate($siteUrl);

        if ($sessionId === null) {
            return self::FAILURE;
        }

        try {
            $findings = $this->scanDashboards($siteUrl, $sessionId, $targets, $scanner);
        } catch (Throwable $e) {
            $this->error('Gagal memindai dashboard: '.$e->getMessage());

            return self::FAILURE;
        }

        return $this->report($findings);
    }

    /**
     * Dashboard yang dipindai: pilihan --dashboard bila ada, jika tidak maka
     * dashboard yang benar-benar diekspos aplikasi (publik + VIP).
     *
     * @return array<string, int>
     */
    protected function dashboardTargets(): array
    {
        $requested = array_filter(array_map('intval', (array) $this->option('dashboard')));

        if ($requested !== []) {
            return array_combine(
                array_map(fn (int $id): string => 'dashboard '.$id, $requested),
                $requested
            );
        }

        $labels = [
            'publik' => 'metabase.public_dashboard_id',
            'vip' => 'metabase.vip_dashboard_id',
        ];

        $targets = [];

        foreach ($labels as $label => $key) {
            $id = (int) config($key);

            if ($id > 0) {
                $targets[$label] = $id;
            }
        }

        return $targets;
    }

    /**
     * Autentikasi ke API Metabase. Session id hanya disimpan di memori
     * variabel lokal dan tidak pernah dicetak maupun ditulis ke laporan.
     */
    protected function authenticate(string $siteUrl): ?string
    {
        $email = (string) config('metabase.admin_email');
        $password = (string) config('metabase.admin_password');

        if ($email === '' || $password === '') {
            $this->error('METABASE_ADMIN_EMAIL dan METABASE_ADMIN_PASSWORD wajib diisi untuk memindai dashboard.');

            return null;
        }

        try {
            $response = Http::timeout(15)->post($siteUrl.'/api/session', [
                'username' => $email,
                'password' => $password,
            ]);
        } catch (Throwable $e) {
            $this->error('Metabase tidak dapat dihubungi: '.$e->getMessage());

            return null;
        }

        if ($response->failed()) {
            $this->error('Login admin Metabase ditolak (status '.$response->status().').');

            return null;
        }

        $sessionId = $response->json('id');

        if (! is_string($sessionId) || $sessionId === '') {
            $this->error('Respons login Metabase tidak memuat session id.');

            return null;
        }

        return $sessionId;
    }

    /**
     * Pindai setiap dashboard target dan kumpulkan temuan per kartu.
     *
     * @param  array<string, int>  $targets
     * @return array<int, array{dashboard: string, dashboard_id: int, card: string, card_id: int|null, pii: array<int, string>}>
     */
    protected function scanDashboards(string $siteUrl, string $sessionId, array $targets, PiiScanner $scanner): array
    {
        $findings = [];

        foreach ($targets as $label => $dashboardId) {
            $response = Http::timeout(20)
                ->withToken($sessionId)
                ->get($siteUrl.'/api/dashboard/'.$dashboardId);

            if ($response->failed()) {
                $this->warn("Dashboard {$label} (ID {$dashboardId}) tidak dapat dibaca (status {$response->status()}).");

                continue;
            }

            foreach ($this->cardList($response->json()) as $card) {
                $pii = $this->cardPii($siteUrl, $sessionId, $card, $scanner);

                if ($pii === []) {
                    continue;
                }

                $findings[] = [
                    'dashboard' => $label,
                    'dashboard_id' => $dashboardId,
                    'card' => (string) ($card['name'] ?? 'Tanpa nama'),
                    'card_id' => isset($card['id']) ? (int) $card['id'] : null,
                    'pii' => $pii,
                ];
            }
        }

        return $findings;
    }

    /**
     * Daftar kartu beserta query-nya dari respons dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function cardList(mixed $dashboard): array
    {
        if (! is_array($dashboard) || ! isset($dashboard['dashcards']) || ! is_array($dashboard['dashcards'])) {
            return [];
        }

        $cards = [];

        foreach ($dashboard['dashcards'] as $dashcard) {
            $card = is_array($dashcard) ? ($dashcard['card'] ?? null) : null;

            if (is_array($card)) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * Kolom PII yang dipakai satu kartu.
     *
     * @param  array<string, mixed>  $card
     * @return array<int, string>
     */
    protected function cardPii(string $siteUrl, string $sessionId, array $card, PiiScanner $scanner): array
    {
        $datasetQuery = $card['dataset_query'] ?? null;

        if (! is_array($datasetQuery)) {
            return [];
        }

        $type = (string) ($datasetQuery['type'] ?? '');

        if ($type === self::QUERY_TYPE_NATIVE) {
            $sql = (string) ($datasetQuery['native']['query'] ?? '');

            return $scanner->findInSql($sql);
        }

        if ($type === self::QUERY_TYPE_MBQL) {
            return $this->mbqlPii($siteUrl, $sessionId, $datasetQuery, $scanner);
        }

        return [];
    }

    /**
     * PII pada kartu MBQL: nama field diambil dari metadata tabel sumber.
     *
     * @param  array<string, mixed>  $datasetQuery
     * @return array<int, string>
     */
    protected function mbqlPii(string $siteUrl, string $sessionId, array $datasetQuery, PiiScanner $scanner): array
    {
        $tableId = $datasetQuery['query']['source-table'] ?? null;

        if (! is_int($tableId)) {
            return [];
        }

        $response = Http::timeout(20)
            ->withToken($sessionId)
            ->get($siteUrl.'/api/table/'.$tableId.'/query_metadata');

        if ($response->failed()) {
            return [];
        }

        return $scanner->findInFieldNames($response->json('fields') ?? []);
    }

    /**
     * @param  array<int, array{dashboard: string, dashboard_id: int, card: string, card_id: int|null, pii: array<int, string>}>  $findings
     */
    protected function report(array $findings): int
    {
        if ($findings === []) {
            $this->info('Tidak ada kolom PII terdeteksi pada dashboard yang dipindai.');
            $this->writeReport($findings);

            return self::SUCCESS;
        }

        $rows = array_map(
            fn (array $finding): array => [
                $finding['dashboard'],
                $finding['card'],
                $finding['card_id'] ?? '-',
                implode(', ', $finding['pii']),
            ],
            $findings
        );

        $this->table(['Dashboard', 'Kartu', 'ID Kartu', 'Kolom PII'], $rows);
        $this->error(count($findings).' kartu memuat kolom PII.');
        $this->line('Perbaiki kartu tersebut (mis. pakai agregat, bukan baris individu) lalu pindai ulang.');

        $this->writeReport($findings);

        return self::FAILURE;
    }

    /**
     * Laporan JSON untuk arsip. Sengaja tidak memuat kredensial maupun session id.
     *
     * @param  array<int, array<string, mixed>>  $findings
     */
    protected function writeReport(array $findings): void
    {
        $directory = storage_path('app/security');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/pii-scan-'.CarbonImmutable::now()->format('Y-m-d').'.json';

        file_put_contents($path, json_encode([
            'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            'metabase_version' => null,
            'metabase_site_url' => config('metabase.site_url'),
            'dashboard_ids' => $this->dashboardTargets(),
            'pii_columns_checked' => PiiScanner::PII_COLUMNS,
            'findings' => $findings,
            'finding_count' => count($findings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->line('Laporan: '.$path);
    }
}
