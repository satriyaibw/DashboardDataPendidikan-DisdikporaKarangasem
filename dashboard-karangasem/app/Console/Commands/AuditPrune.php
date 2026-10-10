<?php

namespace App\Console\Commands;

use App\Models\AdminActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPrune extends Command
{
    protected $signature = 'audit:prune
        {--days=365 : Buang log yang lebih tua dari sekian hari}
        {--dry-run : Tampilkan berapa entri yang akan dibuang tanpa menghapus}
        {--chunk=1000 : Jumlah entri per batch penghapusan}';

    protected $description = 'Buang entri audit log yang lebih tua dari ambang retensi';

    /**
     * Ambang maksimum yang diterima. Nilai besar dipakai sebagai penanda
     * "jangan pangkas" agar retensi tak sengaja diatur ke nol.
     */
    private const MAXIMUM_DAYS = 36500;

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('Opsi --days harus minimal 1. Untuk menonaktifkan pemangkasan, batalkan jadwal perintah ini.');

            return self::FAILURE;
        }

        if ($days > self::MAXIMUM_DAYS) {
            $this->error('Opsi --days terlalu besar (maksimum '.self::MAXIMUM_DAYS.' hari).');

            return self::FAILURE;
        }

        $chunk = $this->chunkSize();

        if ($chunk === null) {
            return self::FAILURE;
        }

        $threshold = CarbonImmutable::now()->subDays($days);

        if ($this->option('dry-run')) {
            $this->reportDryRun($threshold);

            return self::SUCCESS;
        }

        return $this->delete($threshold, $chunk);
    }

    /**
     * Ukuran batch, atau null bila tidak valid (pesan sudah dicetak).
     */
    protected function chunkSize(): ?int
    {
        $chunk = (int) $this->option('chunk');

        if ($chunk < 1) {
            $this->error('Opsi --chunk harus minimal 1.');

            return null;
        }

        return $chunk;
    }

    /**
     * Dry run: hanya menghitung. Menghapus log audit adalah operasi yang
     * tidak dapat dibatalkan, jadi operator harus bisa melihat Dampaknya
     * sebelum retention benar-benar dijalankan.
     */
    protected function reportDryRun(CarbonImmutable $threshold): void
    {
        $count = AdminActivityLog::query()
            ->where('created_at', '<', $threshold)
            ->count();

        $this->info("Dry run: {$count} entri lebih tua dari {$threshold->toDateTimeString()} akan dibuang.");
        $this->line('Tidak ada perubahan. Jalankan ulang tanpa --dry-run untuk menerapkan.');
    }

    /**
     * Hapus per batch agar tidak memuat seluruh tabel ke memori dan agar
     * tabel audit tidak terkunci lama saat retensi berjalan.
     */
    protected function delete(CarbonImmutable $threshold, int $chunk): int
    {
        $deleted = 0;

        do {
            $affected = DB::transaction(function () use ($threshold, $chunk): int {
                $ids = AdminActivityLog::query()
                    ->where('created_at', '<', $threshold)
                    ->orderBy('id')
                    ->limit($chunk)
                    ->pluck('id');

                return $ids->isEmpty() ? 0 : AdminActivityLog::query()->whereKey($ids)->delete();
            });

            $deleted += $affected;
        } while ($affected > 0);

        $this->info("Audit log lebih tua dari {$threshold->toDateTimeString()} dibuang: {$deleted} entri.");

        return self::SUCCESS;
    }
}
