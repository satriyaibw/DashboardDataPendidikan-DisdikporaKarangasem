<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StrictTransportSecurity;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);

        config([
            'metabase.site_url' => 'https://metabase.test',
            'metabase.public_dashboard_id' => 2,
            'metabase.vip_dashboard_id' => 4,
            'metabase.embed_ttl' => 600,
        ]);
    }

    protected function vipUser(): User
    {
        $user = User::factory()->create(['is_active' => true, 'expires_at' => null]);
        $user->assignRole('vip');

        return $user;
    }

    public function test_public_dashboard_sends_defensive_security_headers(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader(
                'Permissions-Policy',
                'camera=(); microphone=(); geolocation=(); payment=(); usb=(); fullscreen=(self "https://metabase.test")',
            );
    }

    public function test_admin_login_sends_the_same_defensive_headers(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    public function test_no_global_content_security_policy_is_installed(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_page_with_content_security_policy_does_not_duplicate_frame_options(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeaderMissing('X-Frame-Options');
    }

    public function test_page_without_content_security_policy_sets_frame_options_deny(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_embed_route_keeps_the_phase_four_content_security_policy(): void
    {
        $this->actingAs($this->vipUser())
            ->get('/vip/dashboard')
            ->assertOk()
            ->assertHeader(
                'Content-Security-Policy',
                "frame-src 'self' https://metabase.test; object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
            )
            ->assertHeaderMissing('X-Frame-Options');
    }

    public function test_permissions_policy_allows_fullscreen_for_the_metabase_origin(): void
    {
        $permissionsPolicy = (string) $this->get('/')->headers->get('Permissions-Policy');

        $this->assertStringContainsString('fullscreen=(self "https://metabase.test")', $permissionsPolicy);
    }

    public function test_permissions_policy_denies_camera_microphone_and_geolocation(): void
    {
        $permissionsPolicy = (string) $this->get('/')->headers->get('Permissions-Policy');

        foreach (['camera', 'microphone', 'geolocation', 'payment', 'usb'] as $feature) {
            $this->assertStringContainsString($feature.'=()', $permissionsPolicy);
        }
    }

    /**
     * Nilai header Permissions-Policy yang dihasilkan middleware, diuji
     * langsung agar tidak bergantung pada rute yang fail-closed saat
     * konfigurasi Metabase tidak valid.
     */
    protected function permissionsPolicyFor(?string $siteUrl): string
    {
        config(['metabase.site_url' => $siteUrl]);

        $response = (new SecurityHeaders)->handle(
            Request::create('/'),
            fn (Request $request) => new Response('ok'),
        );

        return (string) $response->headers->get('Permissions-Policy');
    }

    public function test_permissions_policy_falls_back_to_self_when_metabase_origin_is_untrusted(): void
    {
        $this->assertSame(
            'camera=(); microphone=(); geolocation=(); payment=(); usb=(); fullscreen=(self)',
            $this->permissionsPolicyFor('javascript:alert(1)'),
        );

        $this->assertSame(
            'camera=(); microphone=(); geolocation=(); payment=(); usb=(); fullscreen=(self)',
            $this->permissionsPolicyFor(null),
        );
    }

    public function test_admin_panel_still_renders_with_global_headers_installed(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_hsts_is_not_sent_for_plain_http_requests(): void
    {
        config(['app.hsts_enabled' => true]);

        $this->assertFalse($this->strictTransportSecurityFor('http://dash.test/')->headers->has('Strict-Transport-Security'));
    }

    public function test_hsts_is_not_sent_when_it_is_disabled(): void
    {
        config(['app.hsts_enabled' => false]);

        $this->assertFalse($this->strictTransportSecurityFor('https://dash.test/')->headers->has('Strict-Transport-Security'));
    }

    public function test_hsts_is_sent_for_secure_requests_when_enabled(): void
    {
        config(['app.hsts_enabled' => true]);

        $response = $this->strictTransportSecurityFor('https://dash.test/');

        $this->assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_uses_the_configured_max_age(): void
    {
        config(['app.hsts_enabled' => true, 'app.hsts_max_age' => 600]);

        $this->assertSame(
            'max-age=600; includeSubDomains',
            $this->strictTransportSecurityFor('https://dash.test/')->headers->get('Strict-Transport-Security'),
        );
    }

    public function test_generated_urls_use_https_when_force_https_is_enabled(): void
    {
        config(['app.force_https' => false]);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('http://', url('/'));

        config(['app.force_https' => true]);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/'));
        $this->assertStringStartsWith('https://', route('login'));
    }

    /**
     * Respons setelah melewati middleware StrictTransportSecurity untuk URL
     * yang diberikan, dipakai agar test tidak bergantung pada server web.
     */
    protected function strictTransportSecurityFor(string $url): Response
    {
        return (new StrictTransportSecurity)->handle(
            Request::create($url),
            fn (Request $request) => new Response('ok'),
        );
    }
}
