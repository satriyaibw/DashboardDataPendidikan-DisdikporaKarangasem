<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Jalur login VIP yang belum ditutup `VipAccessTest`.
 *
 * `VipAccessTest` membuktikan batas akses (guest, VIP aktif/kedaluwarsa,
 * non-VIP, rate limit). Test di sini berfokus pada apa yang dikembalikan
 * controller login kepada pengguna: validasi, pesan kesalahan, dan cara
 * RateLimiter menghitung percobaan.
 */
class VipLoginControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);
    }

    protected function vipUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
            'expires_at' => now()->addDays(10),
        ], $attributes));
        $user->assignRole('vip');

        return $user;
    }

    /**
     * Kunci RateLimiter disusun dari email **dan** IP, jadi test harus
     * membersihkan keduanya agar tidak saling memengaruhi.
     */
    protected function forgetRateLimitFor(string $email, string $ip = '127.0.0.1'): void
    {
        RateLimiter::clear(strtolower($email).'|'.$ip);
    }

    public function test_guest_can_see_the_login_form(): void
    {
        $this->get('/login')->assertOk()->assertSee('password', false);
    }

    /**
     * Form login memuat endpoint POST-nya sendiri, jadi kesalahan konfigurasi
     * route akanketahuan sebelum pengguna mencoba.
     */
    public function test_the_login_form_posts_to_the_login_route(): void
    {
        $this->get('/login')->assertSee(route('vip.login'), false);
    }

    public function test_empty_credentials_are_rejected_as_a_validation_error(): void
    {
        $this->post('/vip/login', [])
            ->assertRedirect()
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }

    public function test_a_malformed_email_is_rejected_before_any_authentication_attempt(): void
    {
        $user = $this->vipUser();

        $this->post('/vip/login', ['email' => 'bukan-email', 'password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->forgetRateLimitFor($user->email);
    }

    public function test_wrong_credentials_report_a_generic_message(): void
    {
        $user = $this->vipUser();

        $this->post('/vip/login', ['email' => $user->email, 'password' => 'sandi-salah'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertGuest();

        // Pesan harus generik: membedakan "email tidak ada" dari "sandi salah"
        // memberi penyerang daftar email yang valid.
        $errors = session('errors')->getBag('default')->get('email');
        $this->assertSame(['Kredensial tidak valid.'], $errors);

        $this->forgetRateLimitFor($user->email);
    }

    /**
     * Setiap percobaan gagal harus menambah penghitung, jika tidak RateLimiter
     * tidak pernah achieving apa pun dan brute force tidak terhambat.
     */
    public function test_a_failed_attempt_increments_the_rate_limiter(): void
    {
        $user = $this->vipUser();
        $this->forgetRateLimitFor($user->email);

        $key = strtolower($user->email).'|127.0.0.1';

        $this->post('/vip/login', ['email' => $user->email, 'password' => 'sandi-salah']);

        $this->assertSame(1, RateLimiter::attempts($key));
    }

    public function test_exceeding_the_attempt_limit_blocks_further_logins(): void
    {
        $user = $this->vipUser();
        $this->forgetRateLimitFor($user->email);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/vip/login', ['email' => $user->email, 'password' => 'sandi-salah']);
        }

        // Percobaan keenam: kredensial kali ini benar, tetapi diblokir limiter.
        $this->post('/vip/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Terlalu banyak percobaan login',
            session('errors')->getBag('default')->first('email'),
        );

        $this->assertGuest();
        $this->forgetRateLimitFor($user->email);
    }

    /**
     * RateLimiter membersihkan penghitung setelah login berhasil, sehingga
     * admin yang salah ketik sandi beberapa kali tidak terkunci setelah
     * berhasil masuk.
     */
    public function test_a_successful_login_clears_the_attempt_counter(): void
    {
        $user = $this->vipUser();
        $key = strtolower($user->email).'|127.0.0.1';

        $this->post('/vip/login', ['email' => $user->email, 'password' => 'sandi-salah']);
        $this->assertSame(1, RateLimiter::attempts($key));

        $this->post('/vip/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/vip/dashboard');

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    /**
     * Kunci limiter memuat IP, sehingga penyerang dari satu jaringan tidak
     * boleh mengunci pengguna sah di jaringan lain (NAT, korporat).
     */
    public function test_attempts_from_different_ips_do_not_block_each_other(): void
    {
        $user = $this->vipUser();
        $this->forgetRateLimitFor($user->email);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/vip/login', ['email' => $user->email, 'password' => 'sandi-salah']);
        }

        $this->assertTrue(
            RateLimiter::tooManyAttempts(strtolower($user->email).'|203.0.113.10', 5),
            'IP penyerang sendiri harus terkunci.',
        );

        // Pengguna sah dari IP lain tidak ikut terkunci.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/vip/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/vip/dashboard');

        $this->assertAuthenticatedAs($user);

        $this->forgetRateLimitFor($user->email, '203.0.113.10');
    }

    /**
     * Akun yang ada tapi tidak berhak harus ditolak tanpa membocorkan
     *inetteksistensinya lewat pesan yang berbeda.
     */
    public function test_an_authenticated_but_unauthorised_user_is_rejected_on_dashboard_routes(): void
    {
        $user = $this->vipUser(['is_active' => false]);

        $this->actingAs($user)->get('/vip/dashboard')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->vipUser();

        $this->actingAs($user)->post('/vip/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    /**
     * Setelah logout, halaman VIP tidak boleh lagi bisa diakses hanya dengan
     * URL lama.
     */
    public function test_the_dashboard_is_unreachable_after_logout(): void
    {
        $user = $this->vipUser();

        $this->actingAs($user)->get('/vip/dashboard')->assertOk();
        $this->actingAs($user)->post('/vip/logout')->assertRedirect('/login');

        $this->get('/vip/dashboard')->assertRedirect('/login');
    }
}
