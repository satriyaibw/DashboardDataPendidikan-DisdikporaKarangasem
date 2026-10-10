<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAdminUser;
use Tests\TestCase;

/**
 * Uji perintah `audit:prune`: ambang retensi, keamanan opsi, dan dry run.
 *
 * Memakai RefreshDatabase (bukan DatabaseMigrations) karena yang diuji di
 * sini bukan perilaku foreign key, melainkan himpunan baris yang terpengaruh
 * perintah.
 */
class AuditPruneCommandTest extends TestCase
{
    use HasAdminUser;
    use RefreshDatabase;

    /**
     * Buat satu entri audit berumur relatif terhadap sekarang.
     *
     * `created_at` sengaja dipasang lewat forceFill: kolom timestamp tidak
     * fillable (mass assignment ditolak), dan justru itu yang membuat
     * penanggalan manual lewat `create()` tidak bisa diandalkan di sini.
     */
    protected function logAgedBy(int $days): AdminActivityLog
    {
        $log = AdminActivityLog::query()->create([
            'actor_id' => null,
            'action' => 'user.updated',
            'subject_type' => User::class,
            'subject_id' => 1,
        ]);

        $agedAt = now()->subDays($days);

        $log->forceFill(['created_at' => $agedAt, 'updated_at' => $agedAt])->save();

        return $log;
    }

    public function test_it_deletes_entries_older_than_the_retention_threshold(): void
    {
        $old = $this->logAgedBy(400);
        $kept = $this->logAgedBy(10);

        $this->artisan('audit:prune --days=365')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($kept);
    }

    public function test_dry_run_reports_the_count_without_deleting(): void
    {
        $old = $this->logAgedBy(400);
        $this->logAgedBy(10);

        $this->artisan('audit:prune --days=365 --dry-run')
            ->expectsOutputToContain('Dry run: 1 entri')
            ->expectsOutputToContain('Tidak ada perubahan.')
            ->assertSuccessful();

        // Penghapusan log audit tidak dapat dibatalkan, jadi dry run wajib
        // benar-benar tidak mengubah apa pun.
        $this->assertModelExists($old);
        $this->assertSame(2, AdminActivityLog::query()->count());
    }

    public function test_it_rejects_a_retention_of_zero(): void
    {
        $this->logAgedBy(400);

        $this->artisan('audit:prune --days=0')
            ->expectsOutputToContain('--days harus minimal 1')
            ->assertFailed();

        $this->assertSame(1, AdminActivityLog::query()->count());
    }

    public function test_it_rejects_an_absurd_retention(): void
    {
        $this->logAgedBy(400);

        $this->artisan('audit:prune --days=999999')
            ->expectsOutputToContain('terlalu besar')
            ->assertFailed();

        $this->assertSame(1, AdminActivityLog::query()->count());
    }

    public function test_it_rejects_a_non_positive_chunk_size(): void
    {
        $this->logAgedBy(400);

        $this->artisan('audit:prune --chunk=0')
            ->expectsOutputToContain('--chunk harus minimal 1')
            ->assertFailed();

        $this->assertSame(1, AdminActivityLog::query()->count());
    }

    public function test_batched_deletion_removes_everything_older_than_the_threshold(): void
    {
        // Lebih dari satu batch untuk membuktikan perulangan berjalan sampai
        // tidak ada lagi baris yang cocok.
        for ($i = 0; $i < 7; $i++) {
            $this->logAgedBy(400 + $i);
        }

        $kept = $this->logAgedBy(5);

        $this->artisan('audit:prune --days=365 --chunk=2')->assertSuccessful();

        $this->assertSame(1, AdminActivityLog::query()->count());
        $this->assertModelExists($kept);
    }

    public function test_it_succeeds_when_nothing_matches_the_threshold(): void
    {
        $this->logAgedBy(1);

        $this->artisan('audit:prune --days=365')->assertSuccessful();

        $this->assertSame(1, AdminActivityLog::query()->count());
    }

    public function test_threshold_follows_the_application_clock(): void
    {
        // Ambang harus memakai waktu aplikasi, bukan waktu mesin, supaya
        // hasil retensi konsisten dengan yang ditampilkan panel admin.
        Carbon::setTestNow(Carbon::parse('2026-10-10 00:00:00'));

        try {
            $inside = $this->logAgedBy(10);
            $outside = $this->logAgedBy(20);

            $this->artisan('audit:prune --days=15')->assertSuccessful();

            $this->assertModelExists($inside);
            $this->assertModelMissing($outside);
        } finally {
            Carbon::setTestNow();
        }
    }
}
