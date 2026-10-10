<?php

namespace Tests\Unit;

use App\Services\AdminActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Sifat audit logger diuji langsung pada logikanya, bukan lewat panel
 * Filament. `AdminActivityLogTest` membuktikan alurnya bekerja dari sisi
 * UI; test di sini membuktikan aturan whitelist tetap berlaku bila ada
 * pemanggil baru yang mengirim properti di luar daftar.
 */
class AdminActivityLoggerTest extends TestCase
{
    /**
     * Subjek tanpa tabel: aturan penyaringanproperties diuji tanpa
     * menyentuh database.
     */
    protected function subject(): Model
    {
        return new class extends Model {};
    }

    public function test_it_keeps_every_auditable_attribute(): void
    {
        $filtered = AdminActivityLogger::filterProperties([
            'name' => 'Siti',
            'is_active' => true,
            'expires_at' => '2026-12-31',
            'vip_notes' => 'Agen',
            'roles' => 'vip',
        ]);

        $this->assertSame(
            ['name', 'is_active', 'expires_at', 'vip_notes', 'roles'],
            array_keys($filtered),
        );
    }

    /**
     * PII tidak boleh bocor ke audit log hanya karena pemanggil mengirimnya.
     * `password` dan `email` sengaja tidak masuk daftar: jejak audit dibaca
     * banyak pihak, dan kredensial/alamat surel tidak diperlukan untuk
     * merekonstruksi perubahan hak akses.
     */
    public function test_it_drops_attributes_outside_the_whitelist(): void
    {
        $filtered = AdminActivityLogger::filterProperties([
            'name' => 'Siti',
            'password' => 'rahasia-sama-sekali',
            'email' => 'siti@example.com',
            'remember_token' => 'token',
        ]);

        $this->assertSame(['name'], array_keys($filtered));
    }

    /**
     * Nilai polos diperlakukan sebagai "sesudah" saja, sehingga pembaca log
     * tidak perlu menebak kolom mana yang berubah.
     */
    public function test_it_wraps_a_plain_value_as_the_after_side_only(): void
    {
        $filtered = AdminActivityLogger::filterProperties(['name' => 'Siti']);

        $this->assertSame(['before' => null, 'after' => 'Siti'], $filtered['name']);
    }

    public function test_it_preserves_an_explicit_before_and_after_pair(): void
    {
        $filtered = AdminActivityLogger::filterProperties([
            'is_active' => ['before' => true, 'after' => false],
        ]);

        $this->assertSame(['before' => true, 'after' => false], $filtered['is_active']);
    }

    /**
     * Pasangan null -> null tidak pernah terjadi lewat UI, tetapi bisa dibuat
     * pemanggil baru. Membiarkannya masuk membuat 90% log berisi baris yang
     * tidak membawa informasi apa pun.
     */
    public function test_it_drops_pairs_whose_both_sides_are_null(): void
    {
        $filtered = AdminActivityLogger::filterProperties([
            'vip_notes' => ['before' => null, 'after' => null],
            'name' => 'Siti',
        ]);

        $this->assertSame(['name'], array_keys($filtered));
    }

    /**
     * Nilai yang bukan skalar dan bukan punyak `__toString()` tidak dapat
     * disimpan sebagai nilai json yang berguna, jadi dibuang. Objects Eloquent
     * sendiri sengaja TIDAK termasuk kategori ini: model sudah
     * mengimplementasikan `__toString()`, sehingga nilainya tetap aman.
     */
    public function test_it_drops_values_that_cannot_be_stored_as_json_scalars(): void
    {
        $filtered = AdminActivityLogger::filterProperties([
            'roles' => new \stdClass,
            'vip_notes' => fn (): string => 'catatan',
            'name' => 'Siti',
        ]);

        $this->assertSame(['name'], array_keys($filtered));
    }

    /**
     * Objek yang bisa di-cast ke string tetap aman dan sering dipakai
     * (mis. hasil `implode` pada daftar peran), jadi tidak dibuang.
     */
    public function test_it_keeps_stringable_values(): void
    {
        $filtered = AdminActivityLogger::filterProperties([
            'roles' => Str::of('vip, admin'),
        ]);

        $this->assertSame(['before' => null, 'after' => 'vip, admin'], $filtered['roles']);
    }

    /**
     * Menit/waktu ditulis ISO 8601 **dengan offset** supaya pembaca audit
     * bisa membuktikan "masa berlaku diubah pukul berapa" tanpa menebak
     * apakah nilai itu UTC atau waktu server.
     */
    public function test_it_records_datetimes_with_an_explicit_timezone_offset(): void
    {
        $filtered = AdminActivityLogger::filterProperties([
            'expires_at' => [
                'before' => Carbon::parse('2026-01-01 08:00:00'),
                'after' => Carbon::parse('2026-12-31 08:00:00'),
            ],
        ]);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $filtered['expires_at']['before'],
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $filtered['expires_at']['after'],
        );
    }

    /**
     * Kegagalan menulis audit SENGAJA ditelan: panel admin tidak boleh gagal
     * menyimpan perubahan akun hanya karena tabel audit bermasalah. Jejak
     * audit yang hilang lebih mudah diperbaiki daripada perubahan VIP yang
     * tidak tersimpan.
     */
    public function test_a_write_failure_does_not_interrupt_the_admin_action(): void
    {
        Log::spy();

        $subject = new class extends Model
        {
            public function adminActivityLogs(): MorphMany
            {
                throw new RuntimeException('tabel audit tidak bisa ditulis');
            }
        };

        AdminActivityLogger::log('user.updated', $subject, ['is_active' => true]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($subject): bool {
                return $context['action'] === 'user.updated'
                    && $context['subject_type'] === $subject::class
                    && str_contains($context['reason'], 'tabel audit tidak bisa ditulis');
            });
    }
}
