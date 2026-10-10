<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PasswordPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);
    }

    public function test_policy_accepts_a_compliant_password(): void
    {
        $this->assertTrue(PasswordPolicy::accepts('PasswordKuat123'));
    }

    #[DataProvider('weakPasswords')]
    public function test_policy_rejects_non_compliant_passwords(string $password): void
    {
        $this->assertFalse(PasswordPolicy::accepts($password));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'terlalu pendek' => ['Kurang12'],
            'tanpa huruf besar' => ['passwordlemah123'],
            'tanpa huruf kecil' => ['PASSWORDLEMAH123'],
            'tanpa angka' => ['PasswordLemahAbjad'],
            'kosong' => [''],
        ];
    }

    public function test_seeder_generates_a_compliant_random_password_when_env_is_absent(): void
    {
        config(['admin.password' => null]);

        $this->seed();

        $admin = User::where('email', config('admin.email'))->sole();

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertNotSame(config('admin.password'), $admin->password);
    }

    public function test_seeder_rejects_a_weak_admin_password(): void
    {
        config(['admin.password' => 'password']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_PASSWORD tidak memenuhi kebijakan password');

        $this->seed();
    }

    public function test_seeder_accepts_a_strong_admin_password(): void
    {
        config(['admin.password' => 'PasswordKuat123']);

        $this->seed();

        $admin = User::where('email', config('admin.email'))->sole();

        $this->assertTrue(PasswordPolicy::accepts('PasswordKuat123'));
        $this->assertTrue(Hash::check('PasswordKuat123', $admin->password));
    }
}
