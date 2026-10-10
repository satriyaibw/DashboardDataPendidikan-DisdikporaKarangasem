<?php

namespace App\Console\Commands;

use App\Services\MetabaseOrigin;
use App\Services\PiiScanner;
use App\Services\SafeErrorMessage;
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

    /**
     * Batas waktu tunggu (detik) untuk satu permintaan Metabase.
     */
    private const LOGIN_TIMEOUT_SECONDS = 15;

    private const DASHBOARD_TIMEOUT_SECONDS = 20;

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
            $this->error('Gagal memindai dashboard: '.SafeErrorMessage::for($e));

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
            $response = Http::timeout(self::LOGIN_TIMEOUT_SECONDS)->post($siteUrl.'/api/session', [
                'username' => $email,
                'password' => $password,
            ]);
        } catch (Throwable $e) {
            $this->error('Metabase tidak dapat dihubungi: '.SafeErrorMessage::for($e));

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
            $response = Http::timeout(self::DASHBOARD_TIMEOUT_SECONDS)
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
            return $this->mbqlPii($siteUrl, $sessionId, $card, $datasetQuery, $scanner);
        }

        return [];
    }

    /**
     * PII pada kartu MBQL.
     *
     * Urutan sumber data dipilih dari yang paling akurat:
     *
     * 1. `result_metadata` — persis kolom yang dikembalikan kartu, yaitu yang
     *    benar-benar tampil di dashboard.
     * 2. Metadata seluruh tabel sumber — hanya perkiraan yang lebih luas.
     *    Kartu yang hanya menampilkan agregat per kecamatan tetap akan
     *    tercatat punya kolom PII karena tabel sumbernya memang memuat
     *    kolom tersebut. Batas bawah ini sengaja dibuat konservatif:
     *    lebih baik melaporkan terlalu banyak daripada melewatkan PII.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $datasetQuery
     * @return array<int, string>
     */
    protected function mbqlPii(string $siteUrl, string $sessionId, array $card, array $datasetQuery, PiiScanner $scanner): array
    {
        $resultMetadata = $this->resultMetadata($card, $datasetQuery);

        if ($resultMetadata !== []) {
            return $scanner->findInFieldNames($resultMetadata);
        }

        $tableId = $datasetQuery['query']['source-table'] ?? null;

        // Metabase mengirim source-table sebagai angka, namun sebagian versi
        // mengirimnya sebagai string numerik. Keduanya harus dikenali;
        // hanya instanceof(int) akan membuat kartu lolos pemindaian.
        $tableId = is_string($tableId) && ctype_digit($tableId) ? (int) $tableId : $tableId;

        if (! is_int($tableId) || $tableId <= 0) {
            // Sumber query bukan tabel (mis. kartu di dalam kartu). Tidak
            // bisa diperiksa otomatis: dilaporkan supaya titik buta
            // tidak terlihat sebagai "bersih".
            $this->warn(sprintf(
                'Kartu "%s" memakai sumber query yang tidak dapat dipindai otomatis (source-table: %s). Periksa manual.',
                (string) ($card['name'] ?? 'Tanpa nama'),
                is_scalar($tableId) ? var_export($tableId, true) : get_debug_type($tableId),
            ));

            return [];
        }

        $response = Http::timeout(self::DASHBOARD_TIMEOUT_SECONDS)
            ->withToken($sessionId)
            ->get($siteUrl.'/api/table/'.$tableId.'/query_metadata');

        if ($response->failed()) {
            $this->warn(sprintf(
                'Metadata tabel %d tidak dapat dibaca (status %d); kartu "%s" tidak diperiksa.',
                $tableId,
                $response->status(),
                (string) ($card['name'] ?? 'Tanpa nama'),
            ));

            return [];
        }

        return $scanner->findInFieldNames($response->json('fields') ?? []);
    }

    /**
     * Metadata kolom hasil kartu, bila Metabase menyertakan.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $datasetQuery
     * @return array<int, mixed>
     */
    protected function resultMetadata(array $card, array $datasetQuery): array
    {
        foreach ([$card['result_metadata'] ?? null, $datasetQuery['result_metadata'] ?? null] as $metadata) {
            if (is_array($metadata) && $metadata !== []) {
                return $metadata;
            }
        }

        return [];
    }

    /**
     * @param  array<int, array{dashboard: string, dashboard_id: int, card: string, card_id: int|null, pii: array<int, string>}>  $findings
     */
    protected function report(array $findings): int
    {
        $reportWritten = $this->writeReport($findings);

        if ($findings === []) {
            $this->info('Tidak ada kolom PII terdeteksi pada dashboard yang dipindai.');

            // Laporan adalah bukti audit. Gagal menulisnya harus terlihat,
            // bukan lulus diam-diam sambil jejaknya hilang.
            return $reportWritten ? self::SUCCESS : self::FAILURE;
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

        return self::FAILURE;
    }

    /**
     * Laporan JSON untuk arsip. Sengaja tidak memuat kredensial maupun session id.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return bool true bila laporan berhasil ditulis.
     */
    protected function writeReport(array $findings): bool
    {
        $directory = storage_path('app/security');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error('Direktori laporan tidak dapat dibuat: '.$directory);

            return false;
        }

        $path = $directory.'/pii-scan-'.CarbonImmutable::now()->format('Y-m-d').'.json';

        $written = file_put_contents($path, json_encode([
            'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            'metabase_site_url' => config('metabase.site_url'),
            'dashboard_ids' => $this->dashboardTargets(),
            'pii_columns_checked' => PiiScanner::PII_COLUMNS,
            'findings' => $findings,
            'finding_count' => count($findings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($written === false) {
            $this->error('Laporan tidak dapat ditulis: '.$path);

            return false;
        }

        $this->line('Laporan: '.$path);

        return true;
    }
}
