<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeactivateExpiredVips extends Command
{
    protected $signature = 'vip:deactivate-expired
        {--dry-run : Hitung dan tampilkan akun yang akan dinonaktifkan tanpa menulis}
        {--chunk=500 : Jumlah akun per batch pembaruan}';

    protected $description = 'Nonaktifkan akun VIP yang masa aktifnya sudah lewat';

    /**
     * Nama peran yang ditangani perintah ini. Jangan diubah: nilainya sama
     * dengan isi kolom `name` tabel peran Spatie dan sudah dipakai panel
     * admin, middleware `check.vip`, serta seluruh test.
     */
    private const VIP_ROLE = 'vip';

    /**
     * Nama aksi yang ditulis ke `admin_activity_logs`.
     *
     * Berbeda dari `user.updated` milik UserObserver karena pelaku aksi ini
     * adalah sistem terjadwal, bukan admin yang duduk di panel.
     */
    private const AUDIT_ACTION = 'user.auto_deactivated';

    public function handle(): int
    {
        $chunk = $this->chunkSize();

        if ($chunk === null) {
            return self::FAILURE;
        }

        // Ambang memakai waktu aplikasi (APP_TIMEZONE=Asia/Makassar), bukan
        // now()->utc(): `expires_at` dibaca admin dari panel dengan zona
        // waktu yang sama, jadi ambang harus sepadan dengan yang ditampilkan.
        $threshold = CarbonImmutable::now();

        if ($this->option('dry-run')) {
            $this->reportDryRun($threshold);

            return self::SUCCESS;
        }

        return $this->deactivate($threshold, $chunk);
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
     * Query akun yang memenuhi seluruh syarat penonaktifan.
     *
     * Empat syarat sekaligus:
     * - peran `vip` saja, sehingga akun admin tidak pernah tersentuh
     *   apa pun nilai `expires_at`-nya;
     * - `is_active` masih true, sehingga akun yang sudah dinonaktifkan tidak
     *   dihitung lagi dan tidak menghasilkan entri audit berulang;
     * - `expires_at` tidak null, karena langganan tanpa batas masa berlaku
     *   tidak pernah kedaluwarsa;
     * - `expires_at` sudah lewat terhadap ambang waktu aplikasi.
     */
    protected function expiredQuery(CarbonImmutable $threshold): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $threshold)
            ->whereHas(
                'roles',
                fn (Builder $query): Builder => $query->where('name', self::VIP_ROLE)
            );
    }

    /**
     * Dry run: hanya menghitung. Penonaktifan otomatis berjalan tiap malam
     * tanpa pengawasan, jadi operator harus bisa melihat Dampaknya dulu.
     */
    protected function reportDryRun(CarbonImmutable $threshold): void
    {
        $count = $this->expiredQuery($threshold)->count();

        $this->info("Dry run: {$count} akun VIP akan dinonaktifkan (masa aktif habis sebelum {$this->formatted($threshold)}).");
        $this->line('Tidak ada perubahan. Jalankan ulang tanpa --dry-run untuk menerapkan.');
    }

    /**
     * Nonaktifkan per batch agar tabel pengguna tidak terkunci lama dan
     * daftar akun yang diproses tidak dipuat seluruhnya ke memori.
     */
    protected function deactivate(CarbonImmutable $threshold, int $chunk): int
    {
        $deactivated = 0;

        do {
            $batch = DB::transaction(function () use ($threshold, $chunk): Collection {
                $users = $this->expiredQuery($threshold)->orderBy('id')->limit($chunk)->get();

                if ($users->isEmpty()) {
                    return new Collection;
                }

                $now = CarbonImmutable::now();

                // Pembaruan massal: melewati observer karena perintah ini
                // berjalan tanpa sesi, sehingga `UserObserver::updated()`
                // akan menutup diri sendiri (Auth::id() === null) dan jejak
                // audit tidak akan pernah tertulis. Audit ditulis eksplisit
                // di bawah, di luar transaksi, lewat AdminActivityLogger.
                //
                // Keempat syarat selector diulang pada UPDATE, bukan hanya
                // pada SELECT. Tanpa pengulangan itu, operator yang sedang
                // memperpanjang langganan bisa ikut tertimpa: SELECT
                // sudah membaca baris sebagai kedaluwarsa, lalu pembaruan
                // massal meniadakannya semata karena kunci primary-nya cocok.
                // Mengulang syarat membuat PostgreSQL mengevaluasi ulang baris
                // terkini pada saat UPDATE, sehingga akun yang barusan
                // diperpanjang tidak ikut dinonaktifkan.
                User::query()
                    ->whereKey($users->modelKeys())
                    ->where('is_active', true)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $threshold)
                    ->update(['is_active' => false, 'updated_at' => $now]);

                // Yang dikembalikan adalah baris yang benar-benar berubah,
                // bukan hasil SELECT. Kalau pembaruan di atas melewati
                // sebagian akun karena kondisinya sudah berubah di antara
                // SELECT dan UPDATE, `admin_activity_logs` tidak boleh tetap
                // mencatatinya sebagai dinonaktifkan — jejak audit yang
                // bertentangan dengan isi database lebih berbahaya daripada
                // jejak yang hilang.
                return User::query()
                    ->whereKey($users->modelKeys())
                    ->where('is_active', false)
                    ->where('updated_at', $now)
                    ->get();
            });

            foreach ($batch as $user) {
                $this->recordAudit($user);
            }

            $deactivated += $batch->count();
        } while ($batch->isNotEmpty());

        $this->info("{$deactivated} akun VIP dinonaktifkan (masa aktif habis sebelum {$this->formatted($threshold)}).");

        Log::info('vip.deactivated', [
            'event' => 'vip.deactivated',
            'count' => $deactivated,
            'threshold' => $this->formatted($threshold),
        ]);

        return self::SUCCESS;
    }

    /**
     * Tulis jejak audit untuk satu akun yang dinonaktifkan.
     *
     * Nilai yang dikirim hanya `is_active` dan `expires_at`; `name`, `email`,
     * dan `password` sengaja tidak ikut. Whitelist `AdminActivityLogger`
     * tetap dipakai apa adanya supaya atribut baru di model kelak tidak
     * bocor hanya karena lupa memperbarui pemanggilnya.
     */
    protected function recordAudit(User $user): void
    {
        AdminActivityLogger::log(self::AUDIT_ACTION, $user, [
            'is_active' => ['before' => true, 'after' => false],
            'expires_at' => $user->expires_at,
        ]);
    }

    /**
     * Timestamp ISO 8601 dengan offset zona waktu.
     *
     * Bentuk `Y-m-d H:i:s` hasil `__toString()` Carbon tidak menyebut zona
     * waktu, sehingga pembaca log tidak bisa memastikan itu UTC atau waktu
     * server. Offset eksplisit membuat ambang dapat dibuktikan.
     */
    protected function formatted(CarbonImmutable $threshold): string
    {
        return $threshold->format(\DateTimeInterface::ATOM);
    }
}
