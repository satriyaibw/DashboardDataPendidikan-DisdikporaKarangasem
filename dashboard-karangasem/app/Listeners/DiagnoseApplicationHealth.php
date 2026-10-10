<?php

namespace App\Listeners;

use App\Services\SafeErrorMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Diagnosa ketergantungan aplikasi saat route health `/up` dipanggil.
 *
 * Cara kerjanya mengikuti apa adanya cara kerja route `/up` bawaan Laravel.
 * Route tersebut membungkus pemanggilannya dalam try/catch dan membalas 500
 * HANYA bila ada yang melempar exception; tidak ada objek hasil yang
 * dikembalikan ke route. Jadi listener ini tidak melaporkan status — ia
 * melempar, atau diam.
 *
 * Karena `/up` biasanya dipanggil tanpa login (uptime monitor, load
 * balancer, `docker healthcheck`), responsnya tidak boleh memuat apa pun
 * selain status. Rincian kegagalan karena itu hanya masuk ke log aplikasi,
 * sedangkan yang dilempar ke route adalah nama komponen yang gagal tanpa
 * kredensial, host, atau pesan exception asli.
 */
class DiagnoseApplicationHealth
{
    /**
     * Masa berlaku token cache probe (detik).
     */
    private const CACHE_PROBE_TTL_SECONDS = 10;

    /**
     * Nama komponen dalam pesan exception.
     *
     * Nilai inilah satu-satunya yang keluar ke respons HTTP, jadi daftar ini
     * harus tetap generik dan tidak pernah memuat nilai yang membantu
     * penyerang (nama host, nama database, nama bucket).
     *
     * @var array<string, string>
     */
    private const COMPONENT_LABELS = [
        'database' => 'database',
        'cache' => 'cache',
        'storage' => 'storage',
    ];

    /**
     * Komponen yang gagal pada pemeriksaan terakhir.
     *
     * @var array<int, string>
     */
    private array $failures = [];

    public function handle(): void
    {
        $this->failures = [];

        // Ketiga cek tetap dijalankan meski ada yang gagal, supaya log
        // menyebut seluruh penyebab dalam satu kali request. Melempar
        // seketika hanya akan melaporkan penyebab pertama dan menyisakan
        // masalah lain tidak terdeteksi sampai pemeriksaan berikutnya.
        $this->check('database', fn (): bool => $this->databaseIsReachable());
        $this->check('cache', fn (): bool => $this->cacheIsWritable());
        $this->check('storage', fn (): bool => $this->storageIsWritable());

        if ($this->failures !== []) {
            throw new RuntimeException(
                'Health check gagal: '.implode(', ', $this->failures).'.'
            );
        }
    }

    /**
     * Jalankan satu cek dan catat kegagalannya tanpa melempar.
     */
    protected function check(string $component, callable $probe): void
    {
        try {
            if ($probe()) {
                return;
            }

            $this->recordFailure($component, new RuntimeException('Pemeriksaan mengembalikan hasil negatif.'));
        } catch (Throwable $e) {
            $this->recordFailure($component, $e);
        }
    }

    /**
     * Catat satu kegagalan: sanitize untuk log, simpan label generiknya
     * untuk exception yang dilempar ke route `/up`.
     */
    protected function recordFailure(string $component, Throwable $e): void
    {
        $this->failures[] = self::COMPONENT_LABELS[$component];

        Log::warning('Health check gagal.', [
            'component' => $component,
            // Sanitasi lewat SafeErrorMessage: pesan driver database bisa
            // memuat host dan nama database, yang tidak boleh keluar sebagai
            // HTTP response tapi tetap berguna di dalam log aplikasi.
            'reason' => SafeErrorMessage::for($e),
            'exception' => $e::class,
        ]);
    }

    /**
     * Koneksi database default harus menerima satu kueri paling sederhana.
     */
    protected function databaseIsReachable(): bool
    {
        return DB::select('select 1') !== [];
    }

    /**
     * Cache harus benar-benar bisa tulis DAN baca.
     *
     * Kunci dibuat acak supaya probe lama milik request lain tidak pernah
     * dibaca balik sebagai successes, dan `forget` selalu dijalankan agar
     * pemeriksaan tidak menumpuk entri di cache produksi.
     */
    protected function cacheIsWritable(): bool
    {
        $key = 'health-check:'.Str::random(16);
        $value = (string) Str::random(16);

        try {
            Cache::put($key, $value, self::CACHE_PROBE_TTL_SECONDS);

            return Cache::get($key) === $value;
        } finally {
            Cache::forget($key);
        }
    }

    /**
     * Disk `storage` harus bisa menulis file sementara lalu menghapusnya.
     *
     * Kegagalan di sini biasanya berarti volume terisi penuh atau izin
     * direktori salah — keduanya tidak bisa dideteksi lewat database maupun
     * cache, padahal dampaknya nyata (log tidak bisa ditulis, berkas
     * unggahan gagal).
     */
    protected function storageIsWritable(): bool
    {
        $disk = Storage::disk('local');
        $path = 'health-check-'.Str::random(16).'.tmp';

        try {
            // `put()` mengembalikan nilai bool; keberadaan file diverifikasi
            // ulang lewat `exists()` supaya store yang diam-diam menolak
            // penulisan tidak lolos sebagai "sehat".
            $disk->put($path, 'ok');

            return $disk->exists($path);
        } finally {
            $disk->delete($path);
        }
    }
}
