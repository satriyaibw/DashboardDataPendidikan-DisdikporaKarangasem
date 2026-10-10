<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasAdminUser;
use Tests\TestCase;

class VipDashboardTest extends TestCase
{
    use HasAdminUser;
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
        $user = User::factory()->create(array_merge(['is_active' => true, 'expires_at' => null], $attributes));
        $user->assignRole('vip');

        return $user;
    }

    public function test_guest_sees_public_dashboard_with_public_embed_url(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('public.dashboard')
            ->assertViewHas('embedUrl', 'https://metabase.test/public/dashboard/2')
            ->assertSee('/public/dashboard/2');
    }

    public function test_public_dashboard_links_to_the_vip_login_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('login'));
    }

    public function test_public_dashboard_fails_loudly_when_dashboard_id_is_missing(): void
    {
        config(['metabase.public_dashboard_id' => null]);

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);

        $this->get('/');
    }

    public function test_guest_is_redirected_to_login_from_vip_dashboard(): void
    {
        $this->get('/vip/dashboard')->assertRedirect('/login');
    }

    public function test_active_vip_sees_dashboard_with_signed_embed_url(): void
    {
        $response = $this->actingAs($this->vipUser(['expires_at' => now()->addDays(10)]))
            ->get('/vip/dashboard')
            ->assertOk()
            ->assertViewIs('vip.dashboard')
            ->assertViewHas('embedUrl');

        $this->assertStringStartsWith(
            'https://metabase.test/embed/dashboard/',
            $response->viewData('embedUrl'),
        );
        $this->assertStringEndsWith(
            '#bordered=true&titled=true',
            $response->viewData('embedUrl'),
        );
    }

    public function test_expired_vip_is_redirected_to_login_and_logged_out(): void
    {
        $this->actingAs($this->vipUser(['expires_at' => now()->subDay()]))
            ->get('/vip/dashboard')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_inactive_vip_is_redirected_to_login(): void
    {
        $this->actingAs($this->vipUser(['is_active' => false]))
            ->get('/vip/dashboard')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_non_vip_user_is_forbidden_from_vip_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get('/vip/dashboard')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_embed_url_endpoint(): void
    {
        $this->get('/vip/embed-url')->assertRedirect('/login');
    }

    public function test_guest_json_request_to_embed_url_endpoint_returns_401(): void
    {
        $this->getJson('/vip/embed-url')->assertUnauthorized();
    }

    public function test_active_vip_receives_fresh_embed_url_as_json(): void
    {
        $response = $this->actingAs($this->vipUser(['expires_at' => now()->addDays(10)]))
            ->getJson('/vip/embed-url')
            ->assertOk()
            ->assertJsonStructure(['embed_url'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $embedUrl = $response->json('embed_url');

        $this->assertStringStartsWith('https://metabase.test/embed/dashboard/', $embedUrl);
        $this->assertStringEndsWith('#bordered=true&titled=true', $embedUrl);
    }

    public function test_vip_dashboard_page_is_not_cached_by_the_browser(): void
    {
        $this->actingAs($this->vipUser(['expires_at' => now()->addDays(10)]))
            ->get('/vip/dashboard')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_embed_url_endpoint_is_rate_limited(): void
    {
        $user = $this->vipUser(['expires_at' => now()->addDays(10)]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->actingAs($user)->getJson('/vip/embed-url')->assertOk();
        }

        $this->actingAs($user)->getJson('/vip/embed-url')->assertStatus(429);
    }

    public function test_vip_routes_send_csp_header_allowing_metabase_origin(): void
    {
        $user = $this->vipUser(['expires_at' => now()->addDays(10)]);

        $this->actingAs($user)->get('/vip/dashboard')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-src 'self' https://metabase.test; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");

        $this->actingAs($user)->get('/vip/embed-url')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-src 'self' https://metabase.test; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
    }

    public function test_vip_dashboard_disables_refresh_when_ttl_is_not_positive(): void
    {
        config(['metabase.embed_ttl' => 0]);

        $this->actingAs($this->vipUser(['expires_at' => now()->addDays(10)]))
            ->get('/vip/dashboard')
            ->assertOk()
            ->assertViewHas('refreshIntervalSeconds', 0);
    }

    public function test_vip_dashboard_refreshes_before_the_token_expires(): void
    {
        $this->actingAs($this->vipUser(['expires_at' => now()->addDays(10)]))
            ->get('/vip/dashboard')
            ->assertOk()
            ->assertViewHas('refreshIntervalSeconds', 480)
            ->assertViewHas('embedTtlSeconds', 600);
    }

    public function test_admin_panel_is_not_affected_by_metabase_csp_header(): void
    {
        $response = $this->actingAs($this->adminUserWithMultiFactorAuthentication())->get('/admin');

        $response->assertOk();
        $this->assertStringNotContainsString(
            'metabase.test',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }
}
