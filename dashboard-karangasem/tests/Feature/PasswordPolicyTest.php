<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected int $vipRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->vipRoleId = Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web'])->getKey();
    }

    protected function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    protected function createFormData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'User Baru',
            'email' => 'user.baru@example.com',
            'password' => 'PasswordKuat123',
            'expires_at' => null,
            'is_active' => true,
            'vip_notes' => null,
            // Select relationship memakai key relasi (ID role), bukan nama role.
            'roles' => [$this->vipRoleId],
        ], $overrides);
    }

    public function test_weak_password_is_rejected_on_create(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm($this->createFormData(['password' => 'password']))
            ->call('create')
            ->assertHasFormErrors(['password' => 'Password minimal 12 karakter.']);

        $this->assertDatabaseMissing('users', ['email' => 'user.baru@example.com']);
    }

    public function test_password_without_mixed_case_is_rejected(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm($this->createFormData(['password' => 'passwordlemah123']))
            ->call('create')
            ->assertHasFormErrors(['password' => 'Password harus memuat huruf besar dan huruf kecil.']);

        $this->assertDatabaseMissing('users', ['email' => 'user.baru@example.com']);
    }

    public function test_password_without_numbers_is_rejected(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm($this->createFormData(['password' => 'PasswordLemahAbjad']))
            ->call('create')
            ->assertHasFormErrors(['password' => 'Password harus memuat minimal satu angka.']);

        $this->assertDatabaseMissing('users', ['email' => 'user.baru@example.com']);
    }

    public function test_strong_password_is_accepted_on_create(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm($this->createFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'user.baru@example.com')->sole();

        $this->assertTrue(Hash::check('PasswordKuat123', $user->password));
    }

    public function test_password_is_stored_as_a_hash_not_plaintext(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm($this->createFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'user.baru@example.com')->sole();

        $this->assertTrue(Hash::isHashed($user->password));
        $this->assertNotSame('PasswordKuat123', $user->password);
    }

    public function test_editing_a_user_without_touching_the_password_keeps_it_valid(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $originalHash = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm([
                'name' => 'Nama Diubah',
                'password' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Nama Diubah', $user->name);
        $this->assertSame($originalHash, $user->password);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_admin_panel_requires_authentication_for_user_management(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin/users')
            ->assertOk();
    }
}
