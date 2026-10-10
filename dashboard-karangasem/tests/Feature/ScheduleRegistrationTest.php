<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Uji registrasi scheduler di `bootstrap/app.php`.
 *
 * Assertion sengaja membaca objek `Schedule` langsung, bukan output
 * `artisan schedule:list`. Output `schedule:list` adalah tabel yang
 * lebarnya ikut versi Laravel, jadi mengujinya membuat test ini rapuh tanpa
 * menambah cakupan apa pun — yang penting benar-benar event mana yang
 * terdaftar.
 */
class ScheduleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `withSchedule()` mendaftarkan callback lewat `Artisan::starting()`,
        // sehingga daftar event baru terisi setelah aplikasi konsol
        // di-bootstrap. Menjalankan satu perintah Artisan menjadi pemicunya,
        // persis seperti yang dilakukan cron `schedule:run`.
        $this->artisan('schedule:list')->run();
    }

    /**
     * Semua event yang menjalankan satu potongan nama perintah, tanpa duplikat.
     *
     * `withSchedule()` mendaftarkan callback-nya lewat `afterResolving()`, dan
     * `afterResolving()` dipanggil pada SETIAP kali `Schedule` di-resolve —
     * bukan hanya yang pertama. Dalam konteks test aplikasi konsol dibangun
     * lebih dari sekali (satu untuk `$this->artisan()`, satu lagi saat
     * perintahnya benar-benar dijalankan), sehingga event terdaftar ganda
     * meskipun blok jadwalnya hanya ditulis sekali.
     *
     * Di produksi hal ini tidak terjadi: `schedule:run` me-resolve `Schedule`
     * tepat satu kali, dan `artisan schedule:list` pun hanya menampilkan
     * empat baris. Karena itu test ini membandingkan himpunan event yang
     * unik, bukan jumlahnya.
     *
     * @return Collection<int, Event>
     */
    protected function eventsRunning(string $commandFragment): Collection
    {
        return collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains($event->command ?? '', $commandFragment))
            ->unique(fn (Event $event): string => $event->command.'|'.$event->expression)
            ->values();
    }

    /**
     * Satu event yang menjalankan perintah tertentu.
     */
    protected function eventFor(string $commandFragment): Event
    {
        $events = $this->eventsRunning($commandFragment);

        $this->assertCount(
            1,
            $events,
            "Perintah `{$commandFragment}` harus terdaftar di scheduler.",
        );

        return $events->first();
    }

    public function test_vip_deactivation_is_scheduled_daily(): void
    {
        $this->assertSame('30 0 * * *', $this->eventFor('vip:deactivate-expired')->expression);
    }

    public function test_audit_prune_is_scheduled_daily(): void
    {
        $this->assertSame('0 3 * * *', $this->eventFor('audit:prune')->expression);
    }

    public function test_observability_sentinels_are_scheduled(): void
    {
        // Kedua perintah sudah mengembalikan exit code non-nol saat Metabase
        // bermasalah, sehingga menjadwalkannya mengubahnya menjadi sinyal
        // yang bisa dibaca uptime monitor tanpa kode tambahan.
        $this->assertSame('0 * * * *', $this->eventFor('metabase:check-embedding')->expression);
        $this->assertSame('0 5 * * 1', $this->eventFor('security:scan-pii')->expression);
    }

    public function test_schedule_uses_the_application_timezone(): void
    {
        $this->assertSame('Asia/Makassar', config('app.timezone'));
    }

    public function test_daily_jobs_run_at_their_intended_wall_clock_time(): void
    {
        // Diuji secara perilaku, bukan dengan membaca properti yang
        // dilindungi: tetapkan waktu acuan, lalu pastikan tanggal jalannya
        // dihitung pada jam dinding WITA (+08:00).
        //
        // Acuan 2026-10-10 01:00 WITA dipilih karena kedua job ada di sisi
        // berbeda hari: 00:30 sudah lewat (jatuh tempo besok), 03:00 belum
        // (jatuh tempo hari ini). Kalau `->timezone()` hilang, keduanya
        // dihitung pada UTC sehingga "00:30" berjalan pukul 08:30 WITA dan
        // penonaktifan VIP molor delapan jam setelah langganan habis.
        $now = Carbon::parse('2026-10-09 17:00:00', 'UTC');

        $this->assertSame(
            '2026-10-11 00:30:00 +08:00',
            $this->eventFor('vip:deactivate-expired')->nextRunDate($now)->format('Y-m-d H:i:s P'),
        );

        $this->assertSame(
            '2026-10-10 03:00:00 +08:00',
            $this->eventFor('audit:prune')->nextRunDate($now)->format('Y-m-d H:i:s P'),
        );
    }

    public function test_audit_prune_runs_after_the_nightly_vip_deactivation(): void
    {
        // Urutan 00:30 → 03:00 dipilih supaya entri audit
        // `user.auto_deactivated` yang baru lahir pada malam itu tidak ikut
        // dipangkas oleh retensi 365 hari, dan pemangkasan tidak berjalan
        // bersamaan dengan backup harian pukul 02:00.
        $vip = $this->minutesSinceMidnight($this->eventFor('vip:deactivate-expired'));
        $audit = $this->minutesSinceMidnight($this->eventFor('audit:prune'));
        $backup = 2 * 60;

        $this->assertLessThan($audit, $vip, 'Penonaktifan VIP harus berjalan sebelum pemangkasan audit.');
        $this->assertLessThan($audit, $backup, 'Pemangkasan audit harus berjalan sesudah backup harian 02:00.');
    }

    /**
     * Jam dinding sebuah event harian, dihitung dari ekspresi cron `M H * * *`.
     *
     * Perbandingan dilakukan pada jam dinding, bukan pada `nextRunDate()`:
     * nilai `nextRunDate()` bergantung pada jam berapa test dijalankan, sehingga
     * hasilnya berayun antara lulus dan gagal tergantung waktu.
     */
    protected function minutesSinceMidnight(Event $event): int
    {
        preg_match('/^(\d+) (\d+) \* \* \*$/', $event->expression, $matches);

        $this->assertNotEmpty($matches, "Ekspresi `{$event->expression}` bukan jadwal harian `M H * * *`.");

        return ((int) $matches[2] * 60) + (int) $matches[1];
    }

    public function test_jobs_that_can_run_long_use_a_lock_and_single_server_execution(): void
    {
        // Tanpa `onOneServer()`, job yang sama akan berjalan di setiap server
        // yang menjalankan `schedule:run`. Tanpa `withoutOverlapping()`, job
        // yang lambat bisa tumpang tindih dengan jalannya sendiri.
        foreach (['vip:deactivate-expired', 'audit:prune', 'metabase:check-embedding'] as $command) {
            $event = $this->eventFor($command);

            $this->assertTrue($event->withoutOverlapping, "`{$command}` harus memakai withoutOverlapping.");
            $this->assertTrue($event->onOneServer, "`{$command}` harus memakai onOneServer.");
        }
    }

    public function test_on_one_server_jobs_require_a_shared_cache_store(): void
    {
        // `onOneServer()` mengunci lewat cache store. Store per-proses
        // (array/file) membuat "hanya satu server" tidak berlaku, karena
        // setiap proses punya salinan lock sendiri.
        $this->assertContains(
            config('cache.default'),
            ['database', 'memcached', 'dynamodb', 'redis'],
            'Cache store default harus mendukung lock lintas-proses untuk onOneServer().',
        );
    }
}
