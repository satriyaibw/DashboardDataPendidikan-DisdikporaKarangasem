<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasAdminUser;
use Tests\TestCase;

class AdminMultiFactorAuthenticationTest extends TestCase
{
    use HasAdminUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);
    }

    protected function vipUser(): User
    {
        $user = User::factory()->create(['is_active' => true, 'expires_at' => null]);
        $user->assignRole('vip');

        return $user;
    }

    /**
     * Admin bersandi MFA terpasang: kode TOTP selalu dapat diverifikasi.
     */
    protected function adminWithMultiFactor(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->saveAppAuthenticationSecret(AppAuthentication::make()->generateSecret());

        return $admin;
    }

    public function test_panel_requires_multi_factor_authentication(): void
    {
        $this->assertTrue(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired());
    }

    public function test_admin_without_multi_factor_authentication_is_redirected_to_setup(): void
    {
        // Fail-closed: admin yang belum memasang MFA dialihkan ke halaman
        // penyiapan, bukan diberi akses panel.
        $this->actingAs($this->adminUser())
            ->get('/admin')
            ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }

    public function test_admin_with_multi_factor_authentication_can_access_panel(): void
    {
        $this->actingAs($this->adminUserWithMultiFactorAuthentication())
            ->get('/admin')
            ->assertOk();
    }

    public function test_app_authentication_secret_is_hidden_from_serialization(): void
    {
        $user = $this->adminUserWithMultiFactorAuthentication();

        $this->assertArrayNotHasKey('app_authentication_secret', $user->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $user->toArray());
    }

    public function test_app_authentication_secret_is_encrypted_at_rest(): void
    {
        $user = $this->adminUserWithMultiFactorAuthentication();

        $this->assertNotSame(
            $user->getAppAuthenticationSecret(),
            $user->getRawOriginal('app_authentication_secret'),
            'Secret TOTP harus tersimpan terenkripsi di database, bukan plaintext.',
        );
    }

    public function test_password_alone_cannot_complete_admin_login(): void
    {
        $admin = $this->adminWithMultiFactor();

        Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoErrors();

        // Password benar tetapi belum ada kode MFA → sesi belum terbentuk.
        $this->assertGuest();
    }

    public function test_wrong_app_authentication_code_is_rejected(): void
    {
        $admin = $this->adminWithMultiFactor();

        Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'password'])
            ->call('authenticate')
            ->fillForm(['app.code' => '000000'], 'multiFactorChallengeForm')
            ->call('authenticate')
            ->assertHasErrors();

        $this->assertGuest();
    }

    public function test_valid_app_authentication_code_completes_admin_login(): void
    {
        $admin = $this->adminWithMultiFactor();

        Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'password'])
            ->call('authenticate')
            ->fillForm(['app.code' => AppAuthentication::make()->getCurrentCode($admin)], 'multiFactorChallengeForm')
            ->call('authenticate');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_recovery_codes_are_stored_hashed(): void
    {
        $admin = $this->adminWithMultiFactor();

        $codes = AppAuthentication::make()->generateRecoveryCodes();

        $admin->saveAppAuthenticationRecoveryCodes(array_map(
            fn (string $code): string => Hash::make($code),
            $codes,
        ));

        $stored = $admin->fresh()->getAppAuthenticationRecoveryCodes();

        $this->assertTrue(Hash::check($codes[0], $stored[0]));
        $this->assertNotSame($codes[0], $stored[0], 'Kode pemulihan tidak boleh tersimpan plaintext.');
    }

    public function test_vip_login_is_not_gated_by_multi_factor_authentication(): void
    {
        $vip = $this->vipUser();

        $this->post('/vip/login', ['email' => $vip->email, 'password' => 'password'])
            ->assertRedirect('/vip/dashboard');

        $this->assertAuthenticatedAs($vip);
    }
}
