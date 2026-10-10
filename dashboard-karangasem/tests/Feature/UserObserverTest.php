<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `UserObserver` menerjemahkan perubahan Eloquent menjadi jejak audit.
 *
 * Yang paling kritis dari observer ini adalah syaratnya: ia berjalan tanpa
 * sesi admin (seeder, scheduler, job), dan `password` tidak boleh pernah
 * ikut tercatat meski berada di `$fillable` model.
 */
class UserObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);
    }

    /**
     * Aktor audit adalah user yang sedang login. Observer membaca
     * `Auth::id()`, jadi test harus login sungguhan — `actingAs()` tidak
     * cukup untuk event yang dipicu model.
     */
    protected function loginAsActor(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('admin');

        Auth::login($actor);

        return $actor;
    }

    public function test_creating_a_user_by_an_admin_is_recorded(): void
    {
        $actor = $this->loginAsActor();

        $user = User::factory()->create(['name' => 'Siti', 'is_active' => true]);

        $log = $user->adminActivityLogs()->where('action', 'user.created')->sole();

        $this->assertSame($actor->getKey(), $log->actor_id);
        $this->assertSame('Siti', $log->properties['name']['after']);
        $this->assertTrue($log->properties['is_active']['after']);
    }

    public function test_updating_a_user_by_an_admin_records_the_before_and_after(): void
    {
        $this->loginAsActor();

        $user = User::factory()->create(['is_active' => true]);

        $user->update(['is_active' => false]);

        $log = $user->adminActivityLogs()->where('action', 'user.updated')->sole();

        $this->assertTrue($log->properties['is_active']['before']);
        $this->assertFalse($log->properties['is_active']['after']);
    }

    public function test_extending_a_subscription_records_the_expiry_change(): void
    {
        $this->loginAsActor();

        $user = User::factory()->create(['expires_at' => now()->addDays(30)]);
        $newExpiry = now()->addDays(60);

        $user->update(['expires_at' => $newExpiry]);

        $log = $user->adminActivityLogs()->where('action', 'user.updated')->sole();

        $this->assertArrayHasKey('expires_at', $log->properties);
        $this->assertNotSame(
            $log->properties['expires_at']['before'],
            $log->properties['expires_at']['after'],
        );
    }

    /**
     * `password` ada di `$fillable`, sehingga observer harus menyaringnya
     * sendiri. Jejak audit dibaca banyak pihak; hash password tidak punya
     * alasan bisnis untuk ada di sana.
     */
    public function test_a_password_change_never_reaches_the_audit_log(): void
    {
        $this->loginAsActor();

        $user = User::factory()->create(['password' => 'PasswordLama123']);

        $user->update(['password' => 'PasswordBaru456']);

        $log = $user->adminActivityLogs()->where('action', 'user.updated')->sole();

        // `password` satu-satunya perubahan, dan itu bukan atribut yang boleh
        // dicatat: logger menyimpan `null` alih-alih baris `{}` yang kosong.
        $this->assertNull($log->properties);

        // Hash baru maupun lama tidak boleh bocor di kolom mana pun pada
        // baris log.
        $encoded = json_encode($log->attributes);

        $this->assertStringNotContainsString('PasswordBaru456', $encoded);
        $this->assertStringNotContainsString('PasswordLama123', $encoded);
    }

    /**
     * Jejak audit untuk perubahan yang tidak nyata hanya jadi noise dan
     * menyulitkan siapa pun yang meninjau.
     */
    public function test_an_update_that_changes_nothing_is_not_recorded(): void
    {
        $this->loginAsActor();

        $user = User::factory()->create(['name' => 'Tetap Sama']);

        $user->update(['name' => 'Tetap Sama']);

        $this->assertSame(0, $user->adminActivityLogs()->where('action', 'user.updated')->count());
    }

    public function test_changing_only_an_unrelated_column_records_no_auditable_change(): void
    {
        $this->loginAsActor();

        $user = User::factory()->create(['name' => 'Nama Asli']);

        // `email` tidak masuk AUDITABLE_ATTRIBUTES.
        $user->update(['email' => 'baru@example.com']);

        $log = $user->adminActivityLogs()->where('action', 'user.updated')->sole();

        // Tidak ada atribut yang boleh dicatat, jadi logger menyimpan `null`
        // alih-alih objek properties kosong yang tidak membawa informasi.
        $this->assertNull($log->properties);
    }

    /**
     * Seeder dan scheduler berjalan tanpa user login. Baris audit dengan
     * `actor_id` null hanya menjadi noise, jadi observer melewatinya.
     */
    public function test_a_change_without_an_authenticated_actor_is_not_recorded(): void
    {
        $user = User::factory()->create(['name' => 'Nama Awal']);

        $user->update(['name' => 'Nama Berubah']);

        $this->assertDatabaseCount('admin_activity_logs', 0);
    }

    public function test_deleting_a_user_by_an_admin_is_recorded(): void
    {
        $actor = $this->loginAsActor();

        $user = User::factory()->create(['name' => 'Akan Dihapus']);
        $id = $user->getKey();

        $user->delete();

        $log = AdminActivityLog::query()->where('action', 'user.deleted')->sole();

        $this->assertSame($actor->getKey(), $log->actor_id);
        $this->assertSame($id, $log->subject_id);
        $this->assertSame('Akan Dihapus', $log->properties['name']['after']);
    }

    public function test_deleting_a_user_without_an_actor_is_not_recorded(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertDatabaseCount('admin_activity_logs', 0);
    }

    /**
     * `roles` TIDAK lagi dicatat pada `user.created`.
     *
     * Spatie menunda `assignRole()` sampai event `saved`, sehingga saat
     * `created` dipanggil pivot peran belum ada — baik relasi maupun query
     * builder sama-sama menghasilkan kosong. Menuliskan apa pun pada titik ini
     * hanya menghasilkan string kosong yang disalahartikan sebagai "tidak ada
     * peran". Peran dicatat terpisah oleh RoleAssignmentObserver.
     */
    public function test_created_does_not_pretend_to_know_the_roles(): void
    {
        $this->loginAsActor();

        $user = new User([
            'name' => 'Siti',
            'email' => 'siti@example.com',
            'password' => 'PasswordKuat123',
            'is_active' => true,
        ]);

        $user->assignRole('vip');
        $user->save();

        $log = $user->adminActivityLogs()->where('action', 'user.created')->sole();

        $this->assertArrayNotHasKey('roles', $log->properties);
    }

    /**
     * Membuktikan akar penyebabnya, bukan gejalanya: peran memang ada di
     * database, tetapi tidak terlihat oleh observer pada saat event create.
     */
    public function test_the_role_exists_in_the_database_but_not_yet_on_the_model_when_created_fires(): void
    {
        $this->loginAsActor();

        $user = new User([
            'name' => 'Siti',
            'email' => 'siti@example.com',
            'password' => 'PasswordKuat123',
            'is_active' => true,
        ]);

        $user->assignRole('vip');

        // Sebelum save(): Spatie menunda assignment, relasi masih kosong.
        $this->assertCount(0, $user->roles);

        $user->save();

        // Sesudah save(): peran sudah benar-benar tersimpan.
        $this->assertSame('vip', $user->fresh()->roles->pluck('name')->implode(','));
    }
}
