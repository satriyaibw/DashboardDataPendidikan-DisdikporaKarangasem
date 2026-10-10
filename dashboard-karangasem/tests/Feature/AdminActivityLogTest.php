<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AdminActivityLog;
use App\Models\User;
use App\Services\AdminActivityLogger;
use App\Services\TrustedProxyList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
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

    /**
     * Nilai tanggal/waktu pada audit log harus ISO 8601 dengan offset zona
     * waktu, dan `before`/`after` harus memakai format yang sama supaya
     * perubahan masa berlaku bisa dibandingkan dan dibaca tanpa tebakan.
     */
    public function test_datetime_changes_are_recorded_as_iso_8601_with_offset(): void
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

        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

        $before = $log->properties['expires_at']['before'];
        $after = $log->properties['expires_at']['after'];

        $this->assertMatchesRegularExpression($pattern, $before, 'Nilai sebelum harus ISO 8601 dengan offset.');
        $this->assertMatchesRegularExpression($pattern, $after, 'Nilai sesudah harus ISO 8601 dengan offset.');

        // Offset harus benar-benar menyertakan zona waktu; string tanpa
        // offset akan lolos pemeriksaan panjang tetapi ambigu.
        $this->assertNotNull(
            Carbon::parse($before)->getOffset(),
            'Nilai audit harus menyimpan offset zona waktu.'
        );
    }

    public function test_audit_uses_the_client_ip_from_a_trusted_proxy(): void
    {
        $admin = $this->adminUserWithMultiFactorAuthentication();
        $user = User::factory()->create();

        $this->withRequest(
            $this->proxiedRequest(server: ['REMOTE_ADDR' => '127.0.0.1'], forwardedFor: '203.0.113.10'),
        );

        AdminActivityLogger::log('user.updated', $user, ['is_active' => ['before' => true, 'after' => false]]);

        $this->assertSame('203.0.113.10', AdminActivityLog::query()->sole()->ip);
    }

    /**
     * Header X-Forwarded-Hanya dipercaya bila proxy-nya terdaftar. Membaca
     * header itu langsung tanpa validasi akan membuat IP pada audit log bisa
     * dipalsukan oleh klien mana pun.
     */
    public function test_audit_ignores_forwarded_ip_from_an_untrusted_proxy(): void
    {
        $admin = $this->adminUserWithMultiFactorAuthentication();
        $user = User::factory()->create();

        $this->withRequest(
            $this->proxiedRequest(server: ['REMOTE_ADDR' => '198.51.100.7'], forwardedFor: '203.0.113.10'),
        );

        AdminActivityLogger::log('user.updated', $user, ['is_active' => ['before' => true, 'after' => false]]);

        $this->assertSame('198.51.100.7', AdminActivityLog::query()->sole()->ip);
    }

    /**
     * Request yang tampak datang dari proxy tepercaya (127.0.0.1), dengan
     * daftar proxy diambil dari bootstrap/app.php memakai TRUSTED_PROXIES.
     */
    protected function proxiedRequest(array $server, string $forwardedFor): Request
    {
        $request = Request::create(
            '/admin/users',
            'GET',
            server: $server + [
                'HTTP_X_FORWARDED_FOR' => $forwardedFor,
                'HTTP_USER_AGENT' => 'AuditProbe/1.0',
            ],
        );

        $request->setTrustedProxies(
            TrustedProxyList::fromEnvironment(),
            Request::HEADER_X_FORWARDED_FOR,
        );

        return $request;
    }

    /**
     * Pasang request sebagai request "saat ini" supaya facade Request
     * (dipakai AdminActivityLogger) melihatnya.
     */
    protected function withRequest(Request $request): void
    {
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
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
