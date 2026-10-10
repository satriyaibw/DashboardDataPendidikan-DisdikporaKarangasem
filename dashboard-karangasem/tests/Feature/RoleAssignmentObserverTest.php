<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Perubahan peran harus masuk ke jejak audit.
 *
 * Peran tidak bisa dicatat di `user.created`: Spatie menunda `assignRole()`
 * sampai event `saved`, sehingga saat `created` dipanggil pivot peran belum
 * ada. Observer ini menutup celah itu lewat event Spatie, yang dipancarkan
 * tepat setelah pivot terpasang.
 */
class RoleAssignmentObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);
    }

    /**
     * Aktor audit dibaca dari `Auth::id()`, jadi test harus login sungguhan.
     */
    protected function loginAsActor(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('admin');

        Auth::login($actor);

        return $actor;
    }

    protected function vipUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Siti',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Test ini seluruhnya bergantung pada event Spatie benar-benar
     * dipancarkan. Bila sakelarnya dimatikan, test gagal, bukan lulus diam-diam.
     */
    public function test_spatie_role_events_are_enabled(): void
    {
        $this->assertTrue(
            config('permission.events_enabled'),
            'Jejak audit peran bergantung pada event Spatie.',
        );
    }

    public function test_assigning_a_role_is_recorded_with_a_readable_name(): void
    {
        $this->loginAsActor();
        $user = $this->vipUser();

        $user->assignRole('vip');

        $log = $user->adminActivityLogs()->where('action', 'user.role_attached')->sole();

        $this->assertSame('vip', $log->properties['roles']['after']);
    }

    public function test_the_actor_is_recorded_on_a_role_change(): void
    {
        $actor = $this->loginAsActor();
        $user = $this->vipUser();

        $user->assignRole('vip');

        $log = $user->adminActivityLogs()->where('action', 'user.role_attached')->sole();

        $this->assertSame($actor->getKey(), $log->actor_id);
    }

    public function test_several_roles_are_recorded_in_a_stable_order(): void
    {
        $this->loginAsActor();
        $user = $this->vipUser();

        $user->assignRole(['vip', 'admin']);

        $log = $user->adminActivityLogs()->where('action', 'user.role_attached')->sole();

        // Diurutkan agar dua peninjau membaca baris log yang sama mendapat
        // teks yang identik, bukan bergantung pada urutan attach.
        $this->assertSame('admin, vip', $log->properties['roles']['after']);
    }

    public function test_detaching_a_role_is_recorded(): void
    {
        $this->loginAsActor();
        $user = $this->vipUser();
        $user->assignRole('vip');

        $user->removeRole('vip');

        // Nama peran dibaca dari tabel peran, bukan dari relasi model: pada
        // event detach pivot-nya sudah dilepas, sehingga relasi sudah kosong.
        $log = $user->adminActivityLogs()->where('action', 'user.role_detached')->sole();

        $this->assertSame('vip', $log->properties['roles']['after']);
    }

    /**
     * Peran adalah bagian dari keputusan hak akses, jadi turunnya hak akses
     * harus meninggalkan jejak selebar perubahan itu sendiri.
     */
    public function test_replacing_a_role_leaves_both_the_old_and_the_new_one_in_the_trail(): void
    {
        $this->loginAsActor();
        $user = $this->vipUser();

        $user->assignRole('vip');
        $user->syncRoles(['admin']);

        $attached = $user->adminActivityLogs()
            ->where('action', 'user.role_attached')
            ->latest('id')
            ->firstOrFail();

        $detached = $user->adminActivityLogs()->where('action', 'user.role_detached')->sole();

        $this->assertSame('admin', $attached->properties['roles']['after']);
        $this->assertSame('vip', $detached->properties['roles']['after']);
    }

    /**
     * Seeder dan scheduler berjalan tanpa sesi admin. Entri dengan
     * `actor_id` null hanya menjadi noise, jadi dilewati.
     */
    public function test_a_role_change_without_an_actor_is_not_recorded(): void
    {
        $user = $this->vipUser();

        $user->assignRole('vip');

        $this->assertDatabaseCount('admin_activity_logs', 0);
    }

    /**
     * Jejak audit peran tidak boleh ikut membawa data akun yang tidak
     * perlu. Hanya daftar nama peran yang dicatat.
     */
    public function test_a_role_change_records_nothing_beyond_the_role_names(): void
    {
        $this->loginAsActor();
        $user = $this->vipUser(['name' => 'Rahasia', 'email' => 'rahasia@example.com']);

        $user->assignRole('vip');

        $log = $user->adminActivityLogs()->where('action', 'user.role_attached')->sole();

        $this->assertSame(['roles'], array_keys($log->properties));
    }

    /**
     * Akun yang dibuat lengkap dengan perannya harus meninggalkan jejak dua
     * baris: pembuatan akunnya, lalu perannya. Inilah urutan yang sebenarnya
     * terjadi — dan alasan `roles` tidak bisa ikut pada baris `user.created`.
     *
     * Kasus ini paling mudah terlewat: Spatie memancarkan event dari dalam
     * `assignRole()`, jadi untuk model baru event tiba sebelum `save()` —
     * saat itu akun belum punya primary key dan pivot-nya belum ada.
     */
    public function test_creating_an_account_with_a_role_leaves_a_complete_trail(): void
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

        $created = $user->adminActivityLogs()->where('action', 'user.created')->sole();
        $attached = $user->adminActivityLogs()->where('action', 'user.role_attached')->sole();

        $this->assertSame('Siti', $created->properties['name']['after']);
        $this->assertSame('vip', $attached->properties['roles']['after']);
    }

    /**
     * Penundaan sampai `saved` tidak boleh mengubahnya jadi penulisan
     * berulang.
     *
     * Listener `saved` didaftarkan pada dispatcher global dengan kunci
     * nama class, bukan pada instance — sehingga tetap menyala untuk setiap
     * `save()` berikutnya. Tanpa penanda sekali-pakai, satu peristiwa
     * "pasang peran" menjadi tiga baris audit yang isinya sama persis begitu
     * akun disimpan ulang dua kali.
     */
    public function test_saving_the_account_again_does_not_duplicate_the_role_audit_entry(): void
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

        $user->update(['name' => 'Siti Aminah']);
        $user->update(['name' => 'Siti A.']);

        $this->assertSame(
            1,
            $user->adminActivityLogs()->where('action', 'user.role_attached')->count(),
            'Satu pemasangan peran harus menghasilkan satu baris audit, berapa kali pun akun disimpan ulang.',
        );
    }

    /**
     * Penundaan hanya berlaku pada model tertentu, bukan pada semua akun.
     *
     * Karena listener menempel ke dispatcher global, penyimpanan akun lain
     * akan ikut memicu listener yang tertunda. Guard `isNot` harus
     * menyebabkan baris audit salah akun itu tidak pernah muncul.
     */
    public function test_saving_a_different_account_records_no_role_entry_for_it(): void
    {
        $this->loginAsActor();

        $first = new User([
            'name' => 'Siti',
            'email' => 'siti@example.com',
            'password' => 'PasswordKuat123',
            'is_active' => true,
        ]);
        $first->assignRole('vip');
        $first->save();

        $second = User::factory()->create(['name' => 'Budi']);
        $second->update(['name' => 'Budi Santoso']);

        $this->assertSame(0, $second->adminActivityLogs()->where('action', 'user.role_attached')->count());
    }

    /**
     * Nama peran yang tercatat harus persis peran yang dipasang pada
     * peristiwa itu, bukan seluruh peran yang ada pada akun.
     */
    public function test_the_entry_names_only_the_role_that_was_actually_attached(): void
    {
        $this->loginAsActor();

        $user = User::factory()->create();
        $user->assignRole('vip');

        $firstLog = $user->adminActivityLogs()->where('action', 'user.role_attached')->sole();
        $this->assertSame('vip', $firstLog->properties['roles']['after']);

        $user->assignRole('admin');

        $adminLog = $user->adminActivityLogs()
            ->where('action', 'user.role_attached')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            'admin',
            $adminLog->properties['roles']['after'],
            'Baris kedua harus menyebut peran yang baru dipasang, bukan gabungan seluruh peran akun.',
        );
    }

    /**
     * Peran harus benar-benar terpasang pada database, bukan hanya tercatat
     * di audit. Baris audit yang benar tentang keadaan yang salah tidak
     * menolong siapa pun.
     */
    public function test_the_recorded_role_really_is_attached_to_the_account(): void
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

        $this->assertSame('vip', $user->fresh()->roles->pluck('name')->implode(', '));
    }

    /**
     * Baris audit peran menunjuk ke akun yang tepat, bukan ke aktor yang
     * kebetulan sedang login.
     */
    public function test_the_role_entry_is_attached_to_the_account_whose_role_changed(): void
    {
        $actor = $this->loginAsActor();
        $user = $this->vipUser();
        $other = $this->vipUser();

        $user->assignRole('vip');

        // Dihitung per-aksi, bukan seluruh baris: relasi audit ikut memuat
        // `user.created` dari pembuatan akun, yang bukan bagian dari perilaku
        // yang sedang diuji di sini.
        $this->assertSame(1, $user->adminActivityLogs()->where('action', 'user.role_attached')->count());
        $this->assertSame(0, $other->adminActivityLogs()->where('action', 'user.role_attached')->count());
    }
}
