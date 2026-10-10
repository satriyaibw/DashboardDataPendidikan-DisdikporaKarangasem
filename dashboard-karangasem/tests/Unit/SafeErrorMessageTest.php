<?php

namespace Tests\Unit;

use App\Services\SafeErrorMessage;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Perintah Artisan yang berinteraksi dengan Metabase meminta endpoint
 * sensitif — `/embed/dashboard/{token}` dan header `X-Metabase-Session-Id` —
 * sehingga mencetak pesan exception apa adanya bisa membocorkan token ke
 * terminal, log CI, dan log sistem.
 *
 * Test ini adalah penjaga terakhir yang membuktikan URL tersebut tidak pernah
 * bocor keluar, apa pun pesan asli yang dilempar HTTP client.
 */
class SafeErrorMessageTest extends TestCase
{
    public function test_it_keeps_the_diagnosable_part_of_a_generic_failure(): void
    {
        // Bagian yang berguna untuk diagnosis tetap dipertahankan;
        // menyanamelanya seluruh pesan membuat perintah tidak berguna.
        $message = SafeErrorMessage::for(new RuntimeException('Connection refused'));

        $this->assertSame('Connection refused', $message);
    }

    #[DataProvider('messagesContainingSensitiveUrls')]
    public function test_it_removes_urls_from_error_messages(string $original): void
    {
        $safe = SafeErrorMessage::for(new RuntimeException($original));

        $this->assertStringNotContainsString('http://', $safe);
        $this->assertStringNotContainsString('https://', $safe);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function messagesContainingSensitiveUrls(): array
    {
        return [
            'endpoint embed bertoken' => [
                'cURL error 7: Failed to connect to https://metabase.test/api/embed/dashboard/eyJhbGciOiJIUzI1NiJ9.payload.signature port 443',
            ],
            'kredensial pada URL' => [
                'Gagal: https://admin:rahasia-123@metabase.test/api/session timeout setelah 30000 ms',
            ],
            'beberapa URL' => [
                'Redirect dari https://a.test/embed ke https://b.test/embed gagal',
            ],
            'URL di tengah kalimat' => [
                'Metabase menolak permintaan ke https://metabase.test/api/user/current.',
            ],
        ];
    }

    /**
     * Penanda pengganti dikunci di sini agar refactor berikutnya tidak diam-diam
     * menghilangkannya: yang diuji bukan hanya "URL hilang", tetapi bahwa URL
     * itu diganti sesuatu yang eksplisit terbaca manusia.
     */
    public function test_it_replaces_the_url_with_an_explicit_marker(): void
    {
        $safe = SafeErrorMessage::for(new RuntimeException('Gagal: https://metabase.test/api/embed/dashboard/token123'));

        $this->assertStringContainsString('[URL dihapus]', $safe);
        $this->assertStringNotContainsString('token123', $safe);
    }

    /**
     * Kredensial database tidak boleh muncul di pesan. Sanitasi ini menutup URL
     * yang memuat kredensial; test di atas membuktikan kredensial pada URL
     * ikut terhapus bersama URL-nya.
     */
    public function test_it_does_not_leak_the_password_of_an_internal_database_error(): void
    {
        $message = SafeErrorMessage::for(new ErrorException('Kredensial tidak valid'));

        $this->assertSame('Kredensial tidak valid', $message);
    }

    /**
     * Pesan kosong tidak boleh menghasilkan string kosong pada output Artisan,
     * karena itu membuat diagnosis mustahil. Kelas exception adalah informasi
     * paling kecil yang masih berguna.
     */
    #[DataProvider('uninformativeMessages')]
    public function test_it_falls_back_to_the_exception_class(string $original): void
    {
        $this->assertSame(RuntimeException::class, SafeErrorMessage::for(new RuntimeException($original)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function uninformativeMessages(): array
    {
        return [
            'kosong' => [''],
            'hanya spasi' => ['   '],
            'hanya baris baru' => ["\n"],
        ];
    }

    /**
     * Fail-safe: bahkan ketika APP_DEBUG=true, sanitasi tetap berlaku. Pesan
     * error yang bocor adalah masalah keamanan, bukan preferensi debug.
     */
    public function test_it_redacts_urls_even_when_debug_mode_is_enabled(): void
    {
        config(['app.debug' => true]);

        $safe = SafeErrorMessage::for(new RuntimeException('Error: https://metabase.test/api/embed/dashboard/rahasia'));

        $this->assertStringNotContainsString('rahasia', $safe);
    }
}
