<?php

namespace App\Console\Commands;

use App\Models\AdminActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AuditPrune extends Command
{
    protected $signature = 'audit:prune
        {--days=365 : Buang log yang lebih tua dari sekian hari}';

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

        $threshold = CarbonImmutable::now()->subDays($days);

        $deleted = AdminActivityLog::query()
            ->where('created_at', '<', $threshold)
            ->delete();

        $this->info("Audit log lebih tua dari {$days} hari ({$threshold->toDateTimeString()}) dibuang: {$deleted} entri.");

        return self::SUCCESS;
    }
}
