<?php

namespace Tests\Feature;

use App\Listeners\DiagnoseApplicationHealth;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Uji endpoint health `/up`.
 *
 * `/up` dipanggil tanpa login oleh uptime monitor, load balancer, dan
 * `docker healthcheck`, sehingga dua hal yang wajib diuji: ia mengembalikan
 * 500 saat ada ketergantungan yang mati, dan responsnya tidak pernah
 * membocorkan apa pun soal keadaannya.
 */
class HealthCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Route `/up` meneruskan exception apa adanya saat APP_DEBUG=true,
        // dan halaman debug yang dirender memuat seluruh pesan exception —
        // termasuk nama host database. Memaksa `app.debug` ke false di sini
        // membuat test menguji perilaku produksi yang memang begitu, sekaligus
        // membuktikan alasan kenapa `APP_DEBUG` wajib false di produksi.
        config(['app.debug' => false]);
    }

    public function test_health_endpoint_returns_200_when_everything_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_health_endpoint_returns_json_for_uptime_monitors(): void
    {
        // Route `/up` hanya mengembalikan JSON bila request-nya meminta JSON;
        // bentuk itulah yang dipakai uptime monitor dan
        // `docker healthcheck`.
        $response = $this->getJson('/up');

        $response->assertOk();
        $response->assertExactJson(['status' => 'up']);
    }

    public function test_health_endpoint_returns_500_when_the_database_is_unreachable(): void
    {
        // Kegagalan disimulasikan dengan mengganti facade, BUKAN dengan
        // mematikan container Postgres: mematikan database akan membuat
        // seluruh test suite gagal bersama dan tidak membuktikan apa pun
        // tentang listener-nya.
        DB::shouldReceive('select')->andThrow(
            new RuntimeException('SQLSTATE[08006] connection to server "pgsql" refused')
        );

        $response = $this->getJson('/up');

        $response->assertStatus(500);
        $response->assertExactJson(['status' => 'down']);
    }

    public function test_health_endpoint_returns_500_when_the_cache_is_unusable(): void
    {
        Cache::shouldReceive('put')->andThrow(new RuntimeException('cache store tidak bisa ditulis'));

        $this->getJson('/up')->assertStatus(500);
    }

    public function test_health_endpoint_returns_500_when_storage_is_unusable(): void
    {
        Storage::shouldReceive('disk')->with('local')->andThrow(
            new RuntimeException('disk storage penuh')
        );

        $this->getJson('/up')->assertStatus(500);
    }

    public function test_health_endpoint_never_exposes_internals_in_its_response(): void
    {
        $secret = 'rahasia-yang-tidak-boleh-bocor';
        config(['database.connections.pgsql.password' => $secret]);

        DB::shouldReceive('select')->andThrow(new RuntimeException(
            'SQLSTATE[08006] FATAL: connection to server at "pgsql.internal.karangasem.go.id" '
            .'port 5432 failed, password "'.$secret.'"'
        ));

        $response = $this->getJson('/up');
        $body = $response->getContent();

        $response->assertStatus(500);

        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString('pgsql.internal.karangasem.go.id', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('password', $body);
    }

    public function test_the_failure_reason_is_recorded_in_the_log_instead(): void
    {
        Log::spy();

        DB::shouldReceive('select')->andThrow(
            new RuntimeException('SQLSTATE[08006] connection to server refused')
        );

        $this->getJson('/up')->assertStatus(500);

        // Detail kegagalan harus tetap bisa dibaca operator — di dalam log
        // aplikasi, bukan di respons HTTP.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Health check gagal.'
                    && $context['component'] === 'database'
                    && str_contains($context['reason'], 'SQLSTATE');
            });
    }

    public function test_every_failing_component_is_reported_not_just_the_first_one(): void
    {
        Log::spy();

        DB::shouldReceive('select')->andThrow(new RuntimeException('db mati'));
        Cache::shouldReceive('put')->andThrow(new RuntimeException('cache mati'));
        Storage::shouldReceive('disk')->andThrow(new RuntimeException('disk mati'));

        $this->getJson('/up')->assertStatus(500);

        // Melempar seketika hanya akan menyebut penyebab pertama, dan
        // masalah sisanya baru ketahuan pada pemeriksaan berikutnya.
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_the_listener_is_registered_for_the_health_event(): void
    {
        $this->assertTrue(
            Event::hasListeners(DiagnosingHealth::class),
            'Listener diagnosa kesehatan harus terdaftar pada event DiagnosingHealth.',
        );
    }

    public function test_the_listener_reports_healthy_when_everything_works(): void
    {
        // Dipanggil langsung untuk membuktikan listener tidak melempar pada
        // kondisi sehat — kondisi ini tidak selalu terlihat dari respons
        // `/up` karena route membalas 200 begitu saja tanpa listener
        // terdaftar.
        $listener = new DiagnoseApplicationHealth;
        $listener->handle();

        $this->assertTrue(true, 'Listener tidak melempar saat database, cache, dan disk sehat.');
    }
}
