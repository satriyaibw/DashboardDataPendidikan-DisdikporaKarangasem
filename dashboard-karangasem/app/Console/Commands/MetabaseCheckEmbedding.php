<?php

namespace App\Console\Commands;

use App\Services\MetabaseEmbedService;
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
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->get($metabase->signedUrl());
        } catch (Throwable $e) {
            $this->error('Gagal menghubungi Metabase: '.$e->getMessage());

            return self::FAILURE;
        }

        $status = $response->status();

        $this->line('Status respons embed: '.$status);

        if ($response->serverError()) {
            $this->error('Metabase membalas 5xx — embed tidak dapat dilayani.');

            return self::FAILURE;
        }

        $this->info('Metabase menanggapi permintaan embed (5xx berarti kegagalan, selain itu dianggap dapat dilayani).');

        return self::SUCCESS;
    }
}
