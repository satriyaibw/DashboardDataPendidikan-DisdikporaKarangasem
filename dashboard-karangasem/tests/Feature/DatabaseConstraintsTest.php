<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\User;
use App\Services\AdminActivityLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasAdminUser;
use Tests\TestCase;

/**
 * Integritas referensial dan indeks yang menopang operasi harian.
 *
 * `AdminActivityLogRetentionTest` sudah membuktikan `actor_id` menjadi null
 * ketika admin dihapus; test di sini tidak mengulanginya, melainkan menutup
 * bagian yang belum tersentuh: relasi peran, referensi polymorphic, dan
 * keberadaan indeks yang membuat scheduler tidak memindai seluruh tabel.
 */
class DatabaseConstraintsTest extends TestCase
{
    use HasAdminUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);
    }

    /**
     * Nama index yang ada pada sebuah tabel, dibaca dari katalog sistem.
     * Membaca katalog lebih jujur daripada `EXPLAIN`: pada tabel kecil
     * PostgreSQL memang memilih sequential scan, sehingga query plan tidak
     * bisa membuktikan apa pun tentang performa tabel besar.
     *
     * @return array<int, string>
     */
    protected function indexNamesOn(string $table): array
    {
        return array_map(
            fn ($row): string => $row->indexname,
            DB::select('select indexname from pg_indexes where tablename = ? order by indexname', [$table]),
        );
    }

    /**
     * Scheduler `vip:deactivate-expired` menyaring berdasarkan `expires_at`
     * pada tabel yang tumbuh terus. Tanpa indeks, setiap jadwal memindai
     * seluruh tabel pengguna.
     */
    public function test_expires_at_is_indexed_for_the_deactivation_scheduler(): void
    {
        $this->assertContains(
            'users_expires_at_index',
            $this->indexNamesOn('users'),
            'Kolom expires_at harus berindeks untuk query scheduler.',
        );
    }

    public function test_the_audit_lookup_by_subject_is_indexed(): void
    {
        $indexes = $this->indexNamesOn('admin_activity_logs');

        $this->assertContains(
            'admin_activity_logs_subject_index',
            $indexes,
            'Jejak audit dibaca per-akun; pencarian itu harus berindeks.',
        );
    }

    public function test_the_audit_lookup_by_actor_and_time_is_indexed(): void
    {
        $this->assertContains(
            'admin_activity_logs_actor_id_created_at_index',
            $this->indexNamesOn('admin_activity_logs'),
        );
    }

    public function test_role_assignments_are_removed_together_with_the_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('vip');

        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $user->getKey())->count());

        $user->forceDelete();

        $this->assertSame(
            0,
            DB::table('model_has_roles')->where('model_id', $user->getKey())->count(),
            'Relasi peran yatim akan membuat audit jejak peran tidak terbaca.',
        );
    }

    public function test_roles_survive_the_deletion_of_the_account_holding_them(): void
    {
        $user = User::factory()->create();
        $user->assignRole('vip');

        $roleId = $user->roles()->sole()->getKey();
        $user->forceDelete();

        $this->assertDatabaseHas('roles', ['id' => $roleId]);
    }

    /**
     * `subject_id` sengaja tidak punya foreign key: kolomnya polymorphic dan
     * harus tetap hidup meski akun yang di-audit dihapus. Kalau dihapus, jejak
     * audit ikut lenyap persis saat akun bermasalah dihapus admin — jejak yang
     * paling dibutuhkan justru hilang paling cepat.
     */
    public function test_audit_entries_survive_the_deletion_of_the_account_they_describe(): void
    {
        $user = User::factory()->create();

        AdminActivityLogger::log('user.created', $user, ['name' => 'Siti']);
        $logId = AdminActivityLog::query()->sole()->getKey();
        $subjectId = $user->getKey();

        $user->forceDelete();

        $surviving = AdminActivityLog::query()->findOrFail($logId);

        $this->assertSame($subjectId, $surviving->subject_id);
        $this->assertSame(User::class, $surviving->subject_type);
    }

    /**
     * Jejak audit bukan بيانات sensitif yang perlu ikut terhapus bersama
     * akun: isinya hanya perubahan non-sensitif dan tidak dapat dipakai untuk
     * masuk ke sistem mana pun.
     */
    public function test_deleting_a_user_does_not_cascade_to_the_audit_trail(): void
    {
        $user = User::factory()->create();
        AdminActivityLogger::log('user.created', $user, ['name' => 'Siti']);

        $user->forceDelete();

        $this->assertSame(1, AdminActivityLog::query()->count());
    }

    /**
     * Tabel audit tidak boleh hilang bersama akun terakhir yang tersisa.
     */
    public function test_the_audit_table_survives_after_the_last_user_is_deleted(): void
    {
        $user = User::factory()->create();
        AdminActivityLogger::log('user.created', $user, ['name' => 'Siti']);

        $user->forceDelete();

        $this->assertTrue(Schema::hasTable('admin_activity_logs'));
        $this->assertSame(1, AdminActivityLog::query()->count());
    }

    /**
     * Kolom yang menopang keputusan akses harus ada dan bisa dibaca; kalau
     * salah satu hilang, `CheckVipAccess` akan gagal diam-diam.
     */
    public function test_the_entitlement_columns_exist_on_users(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['is_active', 'expires_at', 'vip_notes']));
    }

    public function test_is_active_defaults_to_true_for_new_accounts(): void
    {
        // Akun baru harus aktif secara default; default false akan membuat
        // setiap akun seeder langsung tidak bisa masuk.
        $user = User::factory()->create();

        $this->assertTrue($user->fresh()->is_active);
    }

    /**
     * Default kolom dibaca dari katalog, bukan dari Eloquent: nilai default
     * database ikut governs baris yang dibuat di luar Eloquent (SQL manual,
     * impor, command Artisan).
     */
    public function test_is_active_carries_a_true_default_in_the_database(): void
    {
        $default = DB::selectOne(
            "select column_default from information_schema.columns where table_name = 'users' and column_name = 'is_active'"
        );

        $this->assertNotNull($default, 'Kolom is_active harus punya default.');
        $this->assertStringContainsString('true', (string) $default->column_default);
    }

    /**
     * `expires_at` boleh null: itu makna "langganan tanpa batas waktu".
     * kolomnya harus nullable, bukan NOT NULL dengan nilai default.
     */
    public function test_expires_at_accepts_null_for_a_lifetime_subscription(): void
    {
        $user = User::factory()->create(['expires_at' => null]);

        $this->assertNull($user->fresh()->expires_at);
    }

    /**
     * Email harus unik. Tanpa ini, satu akun bisa didaftarkan ganda dan
     * jejak audit tidak lagi menunjuk ke satu orang yang jelas.
     */
    public function test_email_addresses_are_unique(): void
    {
        User::factory()->create(['email' => 'kembar@example.com']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['email' => 'kembar@example.com']);
    }
}
