<?php

namespace Tests\Concerns;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Spatie\Permission\Models\Role;

/**
 * Pembuatan akun admin untuk test.
 *
 * Panel admin mewajibkan multi-factor authentication (isRequired: true),
 * sehingga admin biasa akan dialihkan ke halaman setup MFA dan test yang
 * bertujuan menguji hal lain akan mendapat 302 alih-alih 200. Pakai
 * adminUserWithMultiFactorAuthentication() untuk test tersebut.
 */
trait HasAdminUser
{
    protected function adminUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole('admin');

        return $user;
    }

    /**
     * Admin yang sudah memenuhi syarat MFA panel: secret TOTP tersimpan dan
     * mengenkripsi (cast `encrypted` milik Filament), sehingga middleware
     * EnsureMultiFactorAuthenticationIsEnabled membiarkannya masuk.
     */
    protected function adminUserWithMultiFactorAuthentication(array $attributes = []): User
    {
        $user = $this->adminUser($attributes);

        $user->saveAppAuthenticationSecret(AppAuthentication::make()->generateSecret());

        return $user;
    }

    protected function vipRoleId(): int
    {
        return (int) Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web'])->getKey();
    }
}
