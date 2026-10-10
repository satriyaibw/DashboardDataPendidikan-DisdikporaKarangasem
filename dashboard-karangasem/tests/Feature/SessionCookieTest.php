<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SessionCookieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);
    }

    protected function vipUser(): User
    {
        $user = User::factory()->create(['is_active' => true, 'expires_at' => null]);
        $user->assignRole('vip');

        return $user;
    }

    /**
     * Header `Set-Cookie` mentah untuk cookie sesi aplikasi. Sengaja memakai
     * header mentah, bukan objek Cookie Symfony, karena atribut yang dilihat
     * browser ditulis di header tersebut.
     */
    protected function sessionCookieHeader(TestResponse $response): string
    {
        $prefix = config('session.cookie').'=';

        foreach ($response->headers->all('Set-Cookie') as $header) {
            if (str_starts_with((string) $header, $prefix)) {
                return (string) $header;
            }
        }

        return '';
    }

    protected function sessionCookie(): string
    {
        return $this->sessionCookieHeader($this->get('/'));
    }

    /**
     * Nama atribut cookie bersifat case-insensitive (RFC 6265) dan Laravel
     * menuliskannya huruf kecil, jadi pencocokan dilakukan tanpa peduli kasus.
     */
    protected function cookieHasAttribute(string $cookie, string $attribute): bool
    {
        return str_contains(strtolower($cookie), strtolower($attribute));
    }

    public function test_session_cookie_is_http_only_and_same_site_lax(): void
    {
        $cookie = $this->sessionCookie();

        $this->assertNotSame('', $cookie, 'Cookie sesi tidak dikirim pada respons.');
        $this->assertTrue($this->cookieHasAttribute($cookie, 'HttpOnly'), 'Cookie sesi tidak HttpOnly.');
        $this->assertTrue($this->cookieHasAttribute($cookie, 'SameSite=Lax'), 'Cookie sesi bukan SameSite=Lax.');
    }

    public function test_session_cookie_is_not_partitioned(): void
    {
        $cookie = $this->sessionCookie();

        $this->assertFalse($this->cookieHasAttribute($cookie, 'Partitioned'));
        $this->assertFalse($this->cookieHasAttribute($cookie, 'SameSite=None'));
    }

    public function test_session_cookie_is_secure_only_when_configured(): void
    {
        config(['session.secure' => true]);

        $this->assertTrue($this->cookieHasAttribute($this->sessionCookie(), 'Secure'));

        config(['session.secure' => false]);

        $this->assertFalse($this->cookieHasAttribute($this->sessionCookie(), 'Secure'));
    }

    public function test_vip_login_sets_a_hardened_session_cookie(): void
    {
        $user = $this->vipUser();

        $response = $this->post('/vip/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/vip/dashboard');

        $cookie = $this->sessionCookieHeader($response);

        $this->assertNotSame('', $cookie, 'Login VIP tidak menyetel cookie sesi.');
        $this->assertTrue($this->cookieHasAttribute($cookie, 'HttpOnly'), 'Cookie login tidak HttpOnly.');
        $this->assertTrue($this->cookieHasAttribute($cookie, 'SameSite=Lax'), 'Cookie login bukan SameSite=Lax.');
    }
}
