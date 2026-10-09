<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasAdminUser;
use Tests\TestCase;

/**
 * Uji retensi jejak audit terhadap foreign key.
 *
 * Sengaja memakai DatabaseMigrations, bukan RefreshDatabase: RefreshDatabase
 * membungkus tiap test dalam satu transaksi, dan ON DELETE SET NULL pada
 * trigger tidak dapat diverifikasi state akhirnya di dalam transaksi itu.
 */
class AdminActivityLogRetentionTest extends TestCase
{
    use DatabaseMigrations;
    use HasAdminUser;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);
    }

    public function test_log_entry_survives_deletion_of_the_acting_admin(): void
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
        $logId = $log->getKey();

        // nullOnDelete: jejak audit tetap ada, hanya aktornya jadi null.
        $admin->forceDelete();

        $surviving = AdminActivityLog::query()->findOrFail($logId);

        $this->assertNull($surviving->actor_id, 'Jeajak audit harus tetap ada meski admin pelaku dihapus.');
        $this->assertSame('user.deleted', $surviving->action);
    }
}
