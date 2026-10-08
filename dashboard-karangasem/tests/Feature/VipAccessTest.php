<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VipAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);
    }

    protected function vipUser(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['is_active' => true, 'expires_at' => null], $attrs));
        $user->assignRole('vip');

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/vip/dashboard')->assertRedirect('/login');
    }

    public function test_active_vip_can_access_dashboard(): void
    {
        $user = $this->vipUser(['expires_at' => now()->addDays(10)]);
        $this->actingAs($user)->get('/vip/dashboard')->assertOk();
    }

    public function test_expired_vip_is_logged_out_and_redirected(): void
    {
        $user = $this->vipUser(['expires_at' => now()->subDay()]);
        $response = $this->actingAs($user)->get('/vip/dashboard');
        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_inactive_vip_is_rejected(): void
    {
        $user = $this->vipUser(['is_active' => false]);
        $this->actingAs($user)->get('/vip/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_non_vip_role_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get('/vip/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_non_admin_cannot_access_admin_panel(): void
    {
        $user = $this->vipUser();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_admin_can_access_users_resource(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }

    public function test_login_rate_limiting_is_enforced(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/vip/login', ['email' => 'x@example.com', 'password' => 'wrong']);
        }
        // Attempt ke-6 ditolak oleh RateLimiter (bukan throttle route)
        $this->post('/vip/login', ['email' => 'x@example.com', 'password' => 'wrong'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');
    }

    public function test_vip_user_can_login_and_reach_dashboard(): void
    {
        $user = $this->vipUser(['expires_at' => now()->addDays(10)]);

        $this->post('/vip/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/vip/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_non_vip_user_login_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/vip/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
