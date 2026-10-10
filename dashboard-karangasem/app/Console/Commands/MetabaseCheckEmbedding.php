<?php

namespace App\Console\Commands;

use App\Services\MetabaseEmbedService;
use App\Services\SafeErrorMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class MetabaseCheckEmbedding extends Command
{
    protected $signature = 'metabase:check-embedding';

    protected $description = 'Verifikasi konfigurasi embedding Metabase dengan memuat satu URL bertanda tangan';

    /**
     * Batas waktu tunggu respons Metabase.
     */
    private const REQUEST_TIMEOUT_SECONDS = 10;

    public function handle(MetabaseEmbedService $metabase): int
    {
        $problems = $this->configurationProblems($metabase);

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $this->info('Konfigurasi embedding valid.');

        return $this->probeSignedUrl($metabase);
    }

    /**
     * Periksa konfigurasi lewat service yang sama dengan yang dipakai produksi
     * agar aturan validasinya tidak pernah berbeda.
     *
     * @return array<int, string>
     */
    protected function configurationProblems(MetabaseEmbedService $metabase): array
    {
        $problems = [];

        foreach (['public', 'vip'] as $variant) {
            try {
                $variant === 'public' ? $metabase->publicUrl() : $metabase->signedUrl();
            } catch (Throwable $e) {
                $problems[] = $e->getMessage();
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Muat satu URL bertanda tangan dan laporkan status HTTP.
     *
     * Nilai URL (yang memuat token) tidak pernah dicetak: hanya status dan
     * ringkasan konfigurasi yang non-rahasia.
     */
    protected function probeSignedUrl(MetabaseEmbedService $metabase): int
    {
        try {
            $response = Http::connectTimeout(self::REQUEST_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get($metabase->signedUrl());
        } catch (Throwable $e) {
            $this->error('Gagal menghubungi Metabase: '.SafeErrorMessage::for($e));

            return self::FAILURE;
        }

        $status = $response->status();

        $this->line('Status respons embed: '.$status);

        // Hanya 2xx/3xx yang berarti embed benar-benar dapat dilayani.
        //
        // Respons 4xx justru tanda miskonfigurasi yang paling sering terjadi
        // di sini: embedding belum diaktifkan di sisi Metabase, atau token
        // ditolak karena secret tidak identik dengan MB_EMBEDDING_SECRET_KEY.
        // Aturan lama "selain 5xx berarti aman" membuat verifikasi ini
        // melaporkan keberhasilan tepat pada saat embed-nya rusak.
        if ($response->clientError()) {
            $this->error($this->clientErrorExplanation($status));

            return self::FAILURE;
        }

        if ($response->serverError()) {
            $this->error('Metabase membalas 5xx - embed tidak dapat dilayani.');

            return self::FAILURE;
        }

        $this->info('Metabase menanggapi permintaan embed (2xx/3xx berarti dapat dilayani).');

        return self::SUCCESS;
    }

    /**
     * Petunjuk penyebab berdasarkan status, tanpa pernah membocorkan URL
     * bertanda tangan maupun secret.
     */
    protected function clientErrorExplanation(int $status): string
    {
        return match ($status) {
            401, 403 => sprintf(
                'Metabase membalas %d - embedding ditolak. Periksa: embedding aktif di Metabase, '
                .'METABASE_EMBEDDING_SECRET identik dengan MB_EMBEDDING_SECRET_KEY, '
                .'dan Allowed domains untuk iframes memuat domain aplikasi.',
                $status
            ),
            404 => 'Metabase membalas 404 - endpoint /embed/dashboard tidak ditemukan. '
                .'Periksa versi Metabase dan METABASE_VIP_DASHBOARD_ID.',
            default => sprintf('Metabase membalas %d - embed tidak dapat dilayani.', $status),
        };
    }
}
