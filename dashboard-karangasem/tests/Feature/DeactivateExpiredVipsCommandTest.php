<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasAdminUser;
use Tests\TestCase;

/**
 * Uji perintah `vip:deactivate-expired`.
 *
 * Fokus test ini adalah selector-nya: akun mana yang boleh dan tidak boleh
 * dinonaktifkan. Salah satu syarat yang terlewat — `expires_at` null atau
 * akun admin dengan masa aktif lampau — membuat akun yang masih berhak
 * kehilangan aksesnya secara otomatis tanpa jejak yang bisa ditunjuk.
 */
class DeactivateExpiredVipsCommandTest extends TestCase
{
    use HasAdminUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Peran harus ada sebelum `assignRole()` dipakai. `vipRoleId()`
        // memakai firstOrCreate sehingga aman dipanggil berulang.
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->vipRoleId();
    }

    /**
     * Akun VIP dengan masa aktif yang bisa diatur.
     */
    protected function vip(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
            'expires_at' => now()->addMonth(),
        ], $attributes));

        $user->assignRole('vip');

        return $user;
    }

    public function test_it_deactivates_expired_vip_accounts(): void
    {
        $expired = $this->vip(['expires_at' => now()->subMinute()]);

        $this->artisan('vip:deactivate-expired')->assertSuccessful();

        $this->assertFalse($expired->fresh()->is_active);
    }

    public function test_it_keeps_vip_accounts_that_have_not_expired(): void
    {
        $active = $this->vip(['expires_at' => now()->addDay()]);

        $this->artisan('vip:deactivate-expired')->assertSuccessful();

        $this->assertTrue($active->fresh()->is_active);
    }

    public function test_it_never_touches_vip_accounts_without_expiry(): void
    {
        // `expires_at` null berarti langganan tanpa batas waktu, bukan
        // langganan yang sudah habis. Menonaktifkannya akan memutus akses
        // pelanggan yang masih berhak.
        $unlimited = $this->vip(['expires_at' => null]);

        $this->artisan('vip:deactivate-expired')->assertSuccessful();

        $this->assertTrue($unlimited->fresh()->is_active);
    }

    public function test_it_never_touches_admin_accounts(): void
    {
        $admin = $this->adminUser([
            'is_active' => true,
            'expires_at' => now()->subYear(),
        ]);

        $this->artisan('vip:deactivate-expired')->assertSuccessful();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_it_never_touches_accounts_that_are_already_inactive(): void
    {
        $inactive = $this->vip([
            'is_active' => false,
            'expires_at' => now()->subMonth(),
        ]);

        $this->artisan('vip:deactivate-expired')->assertSuccessful();

        $this->assertFalse($inactive->fresh()->is_active);

        // Akun yang sudah nonaktif tidak boleh menghasilkan audit baru
        // setiap malam; kalau iya, `admin_activity_logs` akan penuh baris
        // yang isinya nol perubahan.
        $this->assertSame(0, AdminActivityLog::query()->where('action', 'user.auto_deactivated')->count());
    }

    public function test_dry_run_reports_the_count_without_deactivating(): void
    {
        $this->vip(['expires_at' => now()->subMinute()]);
        $this->vip(['expires_at' => now()->subHour()]);

        $this->artisan('vip:deactivate-expired --dry-run')
            ->expectsOutputToContain('Dry run: 2 akun VIP')
            ->expectsOutputToContain('Tidak ada perubahan.')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->where('is_active', false)->count());
        $this->assertSame(0, AdminActivityLog::query()->where('action', 'user.auto_deactivated')->count());
    }

    public function test_it_rejects_a_non_positive_chunk_size(): void
    {
        $expired = $this->vip(['expires_at' => now()->subMinute()]);

        $this->artisan('vip:deactivate-expired --chunk=0')
            ->expectsOutputToContain('--chunk harus minimal 1')
            ->assertFailed();

        $this->assertTrue($expired->fresh()->is_active);
    }

    public function test_threshold_follows_the_application_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 00:00:00'));

        try {
            $before = $this->vip(['expires_at' => Carbon::parse('2026-10-09 23:59:00')]);
            $after = $this->vip(['expires_at' => Carbon::parse('2026-10-10 00:00:01')]);

            $this->artisan('vip:deactivate-expired')->assertSuccessful();

            $this->assertFalse($before->fresh()->is_active);
            $this->assertTrue($after->fresh()->is_active);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_writes_an_audit_entry_for_each_deactivated_account(): void
    {
        $first = $this->vip(['expires_at' => now()->subDay()]);
        $second = $this->vip(['expires_at' => now()->subDay()]);

        $this->artisan('vip:deactivate-expired')->assertSuccessful();

        // `UserObserver::updated()` hanya mencatat bila ada sesi admin, dan
        // perintah terjadwal berjalan tanpa sesi — jadi audit harus ditulis
        // oleh perintah itu sendiri, bukan observer.
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'user.auto_deactivated',
            'subject_type' => User::class,
            'subject_id' => $first->getKey(),
        ]);

        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'user.auto_deactivated',
            'subject_type' => User::class,
            'subject_id' => $second->getKey(),
        ]);

        $entry = AdminActivityLog::query()
            ->where('action', 'user.auto_deactivated')
            ->where('subject_id', $first->getKey())
            ->firstOrFail();

        $this->assertSame(
            ['before' => true, 'after' => false],
            $entry->properties['is_active'],
        );

        // Jejak audit tidak boleh memuat PII: cukup user_id + expires_at.
        $this->assertArrayNotHasKey('name', $entry->properties);
        $this->assertArrayNotHasKey('vip_notes', $entry->properties);
    }

    public function test_it_keeps_the_expiry_date_so_the_history_stays_readable(): void
    {
        $expired = $this->vip(['expires_at' => now()->subDay()]);

        $this->artisan('vip:deactivate-expired')->assertSuccessful();

        // Akun tidak dihapus dan `expires_at` tidak dikosongkan: jejak
        // riwayat langganan harus tetap bisa dibaca untuk keperluan audit.
        $this->assertDatabaseHas('users', [
            'id' => $expired->getKey(),
            'expires_at' => $expired->fresh()->expires_at,
        ]);
    }

    public function test_it_logs_a_single_summary_line_with_the_threshold(): void
    {
        $this->vip(['expires_at' => now()->subDay()]);

        Log::spy();

        $this->artisan('vip:deactivate-expired')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'vip.deactivated'
                    && $context['event'] === 'vip.deactivated'
                    && $context['count'] === 1
                    // Offset zona waktu wajib eksplisit agar ambang dapat
                    // dibuktikan pembaca log tanpa menebak.
                    && str_contains($context['threshold'], '+08:00');
            });
    }

    public function test_batched_deactivation_processes_every_matching_account(): void
    {
        // Lebih dari satu batch untuk membuktikan perulangan berjalan sampai
        // tidak ada lagi akun yang cocok.
        for ($i = 0; $i < 5; $i++) {
            $this->vip(['expires_at' => now()->subDays($i + 1)]);
        }

        $this->artisan('vip:deactivate-expired --chunk=2')->assertSuccessful();

        $this->assertSame(0, User::query()->where('is_active', true)->count());
        $this->assertSame(5, AdminActivityLog::query()->where('action', 'user.auto_deactivated')->count());
    }

    public function test_an_account_extended_mid_run_is_neither_deactivated_nor_audited(): void
    {
        $user = $this->vip(['expires_at' => now()->subDay()]);

        // Simulasikan operator yang memperpanjang langganan tepat setelah
        // batch terbaca: SELECT sudah melihat baris sebagai kedaluwarsa,
        // lalu `expires_at` berubah sebelum UPDATE dieksekusi.
        //
        // Syarat selector diulang pada UPDATE persis untuk menutup celah ini.
        // Kalau hanya `whereKey()` yang dipakai, baris tetap dinonaktifkan
        // semata karena primary key-nya cocok — pelanggan yang baru membayar
        // langsung kehilangan akses.
        $armed = true;

        DB::listen(function ($query) use (&$armed, $user): void {
            if (! $armed || ! str_contains($query->sql, 'from "users"') || ! str_contains($query->sql, 'limit')) {
                return;
            }

            $armed = false;

            User::query()
                ->whereKey($user->getKey())
                ->update(['expires_at' => now()->addMonth()]);
        });

        $this->artisan('vip:deactivate-expired')->assertSuccessful();

        $this->assertTrue(
            $user->fresh()->is_active,
            'Akun yang diperpanjang saat job berjalan tidak boleh dinonaktifkan.',
        );

        // Jejak audit harus mengikuti kenyataan database. Entri yang
        // mengklaim akun ini dinonaktifkan bertentangan dengan isi tabel
        // `users` dan akan menyesatkan investigator saat insiden.
        $this->assertSame(0, AdminActivityLog::query()->where('action', 'user.auto_deactivated')->count());
    }
}
