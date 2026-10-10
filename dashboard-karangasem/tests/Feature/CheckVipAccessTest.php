<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `CheckVipAccess` adalah gerbang baca murni.
 *
 * Penonaktifan akun kedaluwarsa adalah urusan scheduler `vip:deactivate-expired`.
 * Bila middleware ikut menulis, setiap request HTTP menjadi penulis
 * bersamaan dengan scheduler: dua proses bisa membaca state yang sama lalu
 * sama-sama menulis, dan penonaktifan bisa hilang atau bledup. Test di sini
 * membuktikan middleware tidak pernah menulis apa pun.
 */
class CheckVipAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);

        config([
            'metabase.site_url' => 'https://metabase.test',
            'metabase.embedding_secret' => 'test-secret-yang-cukup-panjang-minimal-32-karakter',
            'metabase.public_dashboard_id' => 2,
            'metabase.vip_dashboard_id' => 4,
            'metabase.embed_ttl' => 600,
        ]);
    }

    protected function vipUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
            'expires_at' => null,
        ], $attributes));
        $user->assignRole('vip');

        return $user;
    }

    /**
     * Query tulis yang menyentuh kolom hak akses (`is_active`, `expires_at`).
     *
     * @var array<int, string>
     */
    protected array $entitlementWrites = [];

    /**
     * Dengarkan query yang mengubah data. Hanya perubahan pada kolom yang
     * menentukan hak akses yang dianggap violating; lihat
     * {@see self::test_the_probe_observes_entitlement_writes()} untuk
     * query lain yang memang terjadi dan mengapa itu bukan efek samping middleware.
     */
    protected function captureEntitlementWrites(): void
    {
        DB::listen(function ($query): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) !== 1) {
                return;
            }

            // Hanya identifier kolom mandiri yang dihitung, bukan
            // substring di tengah nama lain (`is_active_reason`) maupun isi
            // nilai yang kebetulan memuat kata itu.
            if (preg_match('/(?<![\w])(?:"(is_active|expires_at)"|\b(is_active|expires_at)\b)/i', $query->sql) === 1) {
                $this->entitlementWrites[] = $query->sql;
            }
        });
    }

    /**
     * Pesan validasi dari response terakhir, dibaca dari session yang
     * benar-benar dipakai middleware.
     */
    protected function firstValidationMessage(): string
    {
        return (string) session('errors')->getBag('default')->first('email');
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        // `auth` berjalan sebelum `check.vip`, jadi guest dialihkan oleh
        // middleware lebih dulu; middleware CheckVipAccess tidak pernah
        // tereksplorasi pada jalur ini.
        $this->get('/vip/dashboard')->assertRedirect('/login');
    }

    public function test_an_active_vip_passes_through(): void
    {
        $user = $this->vipUser(['expires_at' => Carbon::now()->addDays(10)]);

        $this->actingAs($user)->get('/vip/dashboard')->assertOk();
    }

    public function test_a_vip_without_an_expiry_date_is_treated_as_a_lifetime_subscription(): void
    {
        // `expires_at` null berarti langganan tanpa batas waktu, bukan
        // langganan yang sudah habis. Menolaknya akan memutus pelanggan
        // yang masih berhak.
        $user = $this->vipUser(['expires_at' => null]);

        $this->actingAs($user)->get('/vip/dashboard')->assertOk();
    }

    public function test_an_inactive_vip_is_rejected_and_logged_out(): void
    {
        $user = $this->vipUser(['is_active' => false]);

        $this->actingAs($user)
            ->get('/vip/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_expired_vip_is_rejected_and_logged_out(): void
    {
        $user = $this->vipUser(['expires_at' => Carbon::now()->subSecond()]);

        $this->actingAs($user)
            ->get('/vip/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * `isPast()` memakai perbandingan ketat: tepat pada batas waktu belum
     * "lalu". Menguji batas ini mengunci keputusan tersebut — kalau nanti
     * berubah menjadi `<=`, akses pada detik terakhir ikut berubah dan test
     * ini akan menangkapnya.
     */
    public function test_a_vip_whose_expiry_is_exactly_now_is_still_allowed(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        try {
            $user = $this->vipUser(['expires_at' => Carbon::now()]);

            $this->actingAs($user)->get('/vip/dashboard')->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Satu detik sebelum kedaluwarsa masih boleh masuk.
     *
     * Waktu dikunci secara eksplisit: bila tidak, kedaluwarsa bisa terjadi di
     * tengah eksekusi test (database, HTTP, dan jam sistem berjalan
     * bersamaan), sehingga test ini gagal secara acak — dan test yang
     * kadang merah selalu diabaikan tanpa alasan.
     */
    public function test_a_vip_expiring_one_second_from_now_is_still_allowed(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        try {
            $user = $this->vipUser(['expires_at' => Carbon::now()->addSecond()]);

            $this->actingAs($user)->get('/vip/dashboard')->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Admin tidak punya peran "vip", jadi middleware `role:vip` yang menolak
     * lebih dulu. Yang dibuktikan di sini adalah exclusivity peran: admin
     * tidak otomatis mendapat akses dashboard pelanggan.
     */
    public function test_an_admin_without_the_vip_role_is_not_given_customer_access(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/vip/dashboard')->assertForbidden();
    }

    /**
     * Urutan pemeriksaan: `is_active` diperiksa lebih dulu, sehingga akun yang
     * dinonaktifkan tidak pernah lolos hanya karena masa aktifnya masih
     * panjang.
     */
    public function test_an_inactive_user_with_a_valid_expiry_is_still_rejected(): void
    {
        $user = $this->vipUser([
            'is_active' => false,
            'expires_at' => Carbon::now()->addYears(5),
        ]);

        $this->actingAs($user)->get('/vip/dashboard')->assertRedirect('/login');

        $this->assertGuest();
    }

    /**
     * Kontrak arsitektur: middleware menolak, tetapi status akun di database
     * tetap seperti semula. Penulisan dilakukan scheduler, di luar jalur
     * request.
     */
    public function test_rejecting_an_inactive_vip_never_writes_to_the_database(): void
    {
        $user = $this->vipUser(['is_active' => false]);

        $this->captureEntitlementWrites();

        $this->actingAs($user)->get('/vip/dashboard')->assertRedirect('/login');

        $this->assertSame(
            [],
            $this->entitlementWrites,
            'Middleware CheckVipAccess tidak boleh mengubah status hak akses di database.',
        );
    }

    public function test_rejecting_an_expired_vip_never_writes_to_the_database(): void
    {
        $user = $this->vipUser(['expires_at' => Carbon::now()->subDay()]);

        $this->captureEntitlementWrites();

        $this->actingAs($user)->get('/vip/dashboard')->assertRedirect('/login');

        $this->assertSame(
            [],
            $this->entitlementWrites,
            'Middleware CheckVipAccess tidak boleh mengubah status hak akses di database.',
        );
    }

    /**
     * Bentuk lain dari "tidak menulis": nilai di database tetap utuh
     * setelah penolakan.
     */
    public function test_the_stored_account_state_is_untouched_after_a_rejection(): void
    {
        $user = $this->vipUser(['is_active' => false, 'expires_at' => Carbon::now()->subDay()]);

        $this->actingAs($user)->get('/vip/dashboard')->assertRedirect('/login');

        $user->refresh();

        $this->assertFalse($user->is_active, 'Status akun tidak boleh berubah karena middleware menolak.');
        $this->assertTrue($user->expires_at->isPast());
    }

    /**
     * Menolak seorang VIP berarti mengakhiri sesinya; bila sesi tidak
     * diinvalidasi, token lama masih berlaku untuk request berikutnya.
     */
    public function test_a_rejected_user_cannot_reach_the_dashboard_on_the_next_request(): void
    {
        $user = $this->vipUser(['expires_at' => Carbon::now()->subDay()]);

        $this->actingAs($user)->get('/vip/dashboard')->assertRedirect('/login');
        $this->get('/vip/dashboard')->assertRedirect('/login');
    }

    /**
     * Pesan penolakan dibedakan agar pengguna tahu harus menghubungi siapa:
     * akun dinonaktifkan adalah masalah berbeda dari masa aktif yang habis.
     */
    public function test_the_rejection_message_distinguishes_the_two_failure_causes(): void
    {
        $inactive = $this->vipUser(['is_active' => false]);
        $this->actingAs($inactive)->get('/vip/dashboard')->assertSessionHasErrors('email');
        $this->assertStringContainsString('dinonaktifkan', $this->firstValidationMessage());

        $expired = $this->vipUser(['expires_at' => Carbon::now()->subDay()]);
        $this->actingAs($expired)->get('/vip/dashboard')->assertSessionHasErrors('email');
        $this->assertStringContainsString('berakhir', $this->firstValidationMessage());
    }

    /**
     * Memastikan probe benar-benar bekerja: tanpa test ini, test "tidak
     * menulis" di atas bisa lulus karena alasan salah (listener tidak pernah
     * terpanggil) dan tidak membuktikan apa pun.
     *
     * Probe sengaja dibuat selektif terhadap `is_active`/`expires_at`.
     * Menolak VIP memang menghasilkan query tulis lain di luar kendali
     * middleware: rotasi `remember_token` oleh `Auth::logout()` dan cache
     * rate limiter/session. Semua itu berasal dari framework, bukan dari
     * keputusan middleware, dan tidak mengubah hak akses.
     */
    public function test_the_probe_observes_entitlement_writes(): void
    {
        $this->captureEntitlementWrites();

        $user = $this->vipUser();
        $user->forceFill(['is_active' => false])->save();

        DB::connection()->table('users')->where('id', $user->getKey())->update(['expires_at' => null]);

        $this->assertNotSame(
            [],
            $this->entitlementWrites,
            'Probe harus menangkap query tulis pada kolom hak akses yang disengaja.',
        );
    }
}
