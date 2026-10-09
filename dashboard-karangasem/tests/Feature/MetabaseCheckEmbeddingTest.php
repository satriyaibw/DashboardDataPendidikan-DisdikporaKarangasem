<?php

namespace Tests\Feature;

use App\Services\MetabaseEmbedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetabaseCheckEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sesuaikan konfigurasi metabase per test agar tidak bergantung pada env.
     */
    protected function configureMetabase(array $overrides = []): void
    {
        config(array_merge([
            'metabase.site_url' => 'https://metabase.test',
            'metabase.embedding_secret' => 'test-secret-yang-cukup-panjang-minimal-32-karakter',
            'metabase.public_dashboard_id' => 2,
            'metabase.vip_dashboard_id' => 4,
            'metabase.embed_ttl' => 600,
        ], $overrides));
    }

    public function test_command_fails_when_site_url_is_missing(): void
    {
        $this->configureMetabase(['metabase.site_url' => null]);

        $this->artisan('metabase:check-embedding')
            ->expectsOutputToContain('Metabase site URL is not configured.')
            ->assertFailed();
    }

    public function test_command_fails_when_site_url_uses_an_unsupported_scheme(): void
    {
        $this->configureMetabase(['metabase.site_url' => 'javascript:alert(1)']);

        $this->artisan('metabase:check-embedding')
            ->expectsOutputToContain('Metabase site URL must be an absolute http or https URL.')
            ->assertFailed();
    }

    public function test_command_fails_when_site_url_contains_credentials(): void
    {
        $this->configureMetabase(['metabase.site_url' => 'https://user:pass@metabase.test']);

        $this->artisan('metabase:check-embedding')
            ->expectsOutputToContain('Metabase site URL must not contain credentials.')
            ->assertFailed();
    }

    public function test_command_fails_when_secret_is_missing(): void
    {
        $this->configureMetabase(['metabase.embedding_secret' => null]);

        $this->artisan('metabase:check-embedding')
            ->expectsOutputToContain('Metabase embedding secret is not configured.')
            ->assertFailed();
    }

    public function test_command_fails_when_secret_is_too_short(): void
    {
        $this->configureMetabase(['metabase.embedding_secret' => 'too-short']);

        $this->artisan('metabase:check-embedding')
            ->expectsOutputToContain('Metabase embedding secret must be at least 32 characters long.')
            ->assertFailed();
    }

    /**
     * @dataProvider missingDashboardProviders
     */
    #[DataProvider('missingDashboardProviders')]
    public function test_command_fails_when_a_dashboard_id_is_missing(string $key, string $message): void
    {
        $this->configureMetabase([$key => null]);

        $this->artisan('metabase:check-embedding')
            ->expectsOutputToContain($message)
            ->assertFailed();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function missingDashboardProviders(): array
    {
        return [
            'public' => ['metabase.public_dashboard_id', 'Metabase public dashboard ID is not configured.'],
            'vip' => ['metabase.vip_dashboard_id', 'Metabase VIP dashboard ID is not configured.'],
        ];
    }

    public function test_command_succeeds_when_metabase_answers_ok(): void
    {
        $this->configureMetabase();

        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('metabase:check-embedding')
            ->expectsOutput('Konfigurasi embedding valid.')
            ->assertSuccessful();
    }

    public function test_command_fails_when_metabase_answers_a_server_error(): void
    {
        $this->configureMetabase();

        Http::fake(['*' => Http::response('', 503)]);

        $this->artisan('metabase:check-embedding')
            ->expectsOutputToContain('Status respons embed: 503')
            ->assertFailed();
    }

    public function test_command_output_never_leaks_the_secret_or_the_token(): void
    {
        $this->configureMetabase();

        Http::fake(['*' => Http::response('', 200)]);

        $secret = config('metabase.embedding_secret');

        Artisan::call('metabase:check-embedding');

        $output = Artisan::output();
        $url = app(MetabaseEmbedService::class)->signedUrl();

        $this->assertStringNotContainsString($secret, $output, 'Secret embedding tidak boleh ikut tercetak.');
        $this->assertStringNotContainsString($url, $output, 'URL bertanda tangan tidak boleh ikut tercetak.');
    }
}
