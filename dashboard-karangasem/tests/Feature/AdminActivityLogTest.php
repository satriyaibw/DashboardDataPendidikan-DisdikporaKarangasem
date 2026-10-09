<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasAdminUser;
use Tests\TestCase;

class AdminActivityLogTest extends TestCase
{
    use HasAdminUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);
    }

    protected function createUserFormData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'User Baru',
            'email' => 'user.baru@example.com',
            'password' => 'PasswordKuat123',
            'expires_at' => null,
            'is_active' => true,
            'vip_notes' => null,
            'roles' => [$this->vipRoleId()],
        ], $overrides);
    }

    public function test_admin_creating_a_vip_user_is_recorded(): void
    {
        $admin = $this->adminUserWithMultiFactorAuthentication();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm($this->createUserFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'user.baru@example.com')->sole();
        $log = $user->adminActivityLogs()->sole();

        $this->assertSame('user.created', $log->action);
        $this->assertSame($admin->getKey(), $log->actor_id);
        $this->assertSame('User Baru', $log->properties['name']['after']);
        $this->assertTrue($log->properties['is_active']['after']);
    }

    public function test_extending_a_user_records_the_expiry_change(): void
    {
        $admin = $this->adminUserWithMultiFactorAuthentication();

        $user = User::factory()->create([
            'expires_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('perpanjang', $user)
            ->assertHasNoTableActionErrors();

        $log = $user->adminActivityLogs()->where('action', 'user.updated')->sole();

        $this->assertArrayHasKey('expires_at', $log->properties);
        $this->assertNotNull($log->properties['expires_at']['before']);
        $this->assertNotNull($log->properties['expires_at']['after']);
        $this->assertNotSame(
            $log->properties['expires_at']['before'],
            $log->properties['expires_at']['after'],
            'Perpanjang harus tercatat sebagai perubahan nilai lama -> baru.'
        );
    }

    public function test_deactivating_a_user_is_recorded(): void
    {
        $admin = $this->adminUserWithMultiFactorAuthentication();

        $user = User::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('toggleActive', $user)
            ->assertHasNoTableActionErrors();

        $log = $user->adminActivityLogs()->where('action', 'user.updated')->sole();

        $this->assertTrue($log->properties['is_active']['before']);
        $this->assertFalse($log->properties['is_active']['after']);
    }

    public function test_audit_log_never_records_the_password(): void
    {
        $admin = $this->adminUserWithMultiFactorAuthentication();

        $user = User::factory()->create(['name' => 'Nama Awal']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Nama Diubah', 'password' => 'PasswordKuat999'])
            ->call('save')
            ->assertHasNoFormErrors();

        $log = $user->adminActivityLogs()->where('action', 'user.updated')->sole();

        $this->assertArrayNotHasKey('password', $log->properties);
        $this->assertArrayNotHasKey('remember_token', $log->properties);
        $this->assertSame('Nama Awal', $log->properties['name']['before']);
        $this->assertSame('Nama Diubah', $log->properties['name']['after']);
    }

    public function test_changes_without_an_authenticated_actor_are_not_recorded(): void
    {
        // Seeder dan scheduler berjalan tanpa user login: entri audit dengan
        // actor null hanya jadi noise, jadi dilewati.
        $user = User::factory()->create(['name' => 'Nama Awal']);

        $user->update(['name' => 'Nama Tanpa Admin']);

        $this->assertDatabaseCount('admin_activity_logs', 0);
    }

    public function test_admin_deleting_a_user_is_recorded(): void
    {
        $admin = $this->adminUserWithMultiFactorAuthentication();

        $user = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $user->getKey()])
            ->callAction('delete')
            ->assertHasNoActionErrors();

        $this->assertModelMissing($user);

        $log = AdminActivityLog::query()->where('action', 'user.deleted')->sole();

        $this->assertSame($admin->getKey(), $log->actor_id);
        $this->assertSame($user->getKey(), $log->subject_id);
    }

    public function test_audit_records_ip_and_user_agent_from_the_request(): void
    {
        $admin = $this->adminUserWithMultiFactorAuthentication();

        $user = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('toggleActive', $user);

        $log = $user->adminActivityLogs()->sole();

        $this->assertNotNull($log->user_agent);
        $this->assertNotNull($log->ip);
    }

    public function test_audit_prune_removes_only_old_entries(): void
    {
        // created_at/updated_at tidak masuk $fillable (memang tidak seharusnya
        // diisi massal), jadi waktu lampau diset secara eksplisit.
        $old = AdminActivityLog::create([
            'action' => 'user.created',
            'subject_type' => User::class,
            'subject_id' => 1,
        ]);
        $old->forceFill(['created_at' => now()->subDays(400)])->save();

        $recent = AdminActivityLog::create([
            'action' => 'user.created',
            'subject_type' => User::class,
            'subject_id' => 2,
        ]);
        $recent->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->artisan('audit:prune --days=365')->assertSuccessful();

        $this->assertDatabaseMissing('admin_activity_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('admin_activity_logs', ['id' => $recent->id]);
    }

    public function test_audit_prune_rejects_invalid_retention(): void
    {
        AdminActivityLog::create([
            'action' => 'user.created',
            'subject_type' => User::class,
            'subject_id' => 1,
        ]);

        $this->artisan('audit:prune --days=0')->assertFailed();

        $this->assertDatabaseCount('admin_activity_logs', 1);
    }

    public function test_audit_failure_does_not_break_the_admin_action(): void
    {
        $admin = $this->adminUserWithMultiFactorAuthentication();

        $user = User::factory()->create(['name' => 'Nama Awal']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Tetap Tersimpan'])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Tetap Tersimpan', $user->name);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'user.updated']);
    }
}
