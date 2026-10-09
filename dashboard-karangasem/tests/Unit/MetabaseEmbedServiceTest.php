<?php

namespace Tests\Unit;

use App\Services\MetabaseEmbedService;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class MetabaseEmbedServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'metabase.site_url' => 'https://metabase.test',
            'metabase.embedding_secret' => 'test-secret-yang-cukup-panjang-minimal-32-karakter',
            'metabase.public_dashboard_id' => 2,
            'metabase.vip_dashboard_id' => 4,
            'metabase.embed_ttl' => 600,
        ]);
    }

    protected function service(): MetabaseEmbedService
    {
        return new MetabaseEmbedService;
    }

    /**
     * Ambil segmen token dari URL signed embed.
     */
    protected function extractToken(string $signedUrl): string
    {
        return basename((string) parse_url($signedUrl, PHP_URL_PATH));
    }

    /**
     * Decode token dengan secret milik service — pengujian tidak pernah memakai
     * helper dekode di kode produksi.
     */
    protected function decodeToken(string $token): object
    {
        return JWT::decode($token, new Key('test-secret-yang-cukup-panjang-minimal-32-karakter', 'HS256'));
    }

    public function test_public_url_points_to_public_dashboard_without_token(): void
    {
        $this->assertSame(
            'https://metabase.test/public/dashboard/2',
            $this->service()->publicUrl(),
        );
    }

    public function test_public_url_strips_trailing_slash_from_site_url(): void
    {
        config(['metabase.site_url' => 'https://metabase.test/']);

        $this->assertSame(
            'https://metabase.test/public/dashboard/2',
            $this->service()->publicUrl(),
        );
    }

    public function test_signed_url_uses_embed_dashboard_path_and_fragment(): void
    {
        $signedUrl = $this->service()->signedUrl();

        $this->assertStringStartsWith('https://metabase.test/embed/dashboard/', $signedUrl);
        $this->assertStringEndsWith('#bordered=true&titled=true', $signedUrl);
    }

    public function test_signed_url_token_decodes_with_matching_payload(): void
    {
        $token = $this->extractToken($this->service()->signedUrl());

        $this->assertSame(3, count(explode('.', $token)));

        $payload = $this->decodeToken($token);

        $this->assertSame(4, $payload->resource->dashboard);
        $this->assertSame('{}', json_encode($payload->params));
        $this->assertGreaterThan(time() + 598, $payload->exp);
    }

    public function test_signed_url_encodes_given_params_as_object(): void
    {
        $token = $this->extractToken($this->service()->signedUrl(['kec' => 'Karangasem']));

        $payload = $this->decodeToken($token);

        $this->assertSame('Karangasem', $payload->params->kec);
    }

    public function test_token_with_wrong_secret_fails_signature_verification(): void
    {
        $token = $this->extractToken($this->service()->signedUrl());

        $this->expectException(SignatureInvalidException::class);

        JWT::decode($token, new Key('secret-lain-yang-cukup-panjang-minimal-32-char', 'HS256'));
    }

    public function test_token_with_expired_ttl_is_rejected_as_expired(): void
    {
        config(['metabase.embed_ttl' => -60]);

        $token = $this->extractToken($this->service()->signedUrl());

        $this->expectException(ExpiredException::class);

        $this->decodeToken($token);
    }

    public function test_signed_url_throws_when_embedding_secret_is_missing(): void
    {
        config(['metabase.embedding_secret' => null]);

        $this->expectException(RuntimeException::class);

        $this->service()->signedUrl();
    }

    public function test_signed_url_throws_when_vip_dashboard_id_is_missing(): void
    {
        config(['metabase.vip_dashboard_id' => null]);

        $this->expectException(RuntimeException::class);

        $this->service()->signedUrl();
    }

    public function test_public_url_throws_when_public_dashboard_id_is_missing(): void
    {
        config(['metabase.public_dashboard_id' => null]);

        $this->expectException(RuntimeException::class);

        $this->service()->publicUrl();
    }

    public function test_signed_url_throws_when_site_url_is_not_configured(): void
    {
        config(['metabase.site_url' => null]);

        $this->expectException(RuntimeException::class);

        $this->service()->signedUrl();
    }

    public function test_signed_url_rejects_site_url_without_absolute_http_scheme(): void
    {
        config(['metabase.site_url' => 'metabase.test']);

        $this->expectException(RuntimeException::class);

        $this->service()->signedUrl();
    }

    public function test_signed_url_rejects_site_url_with_non_http_scheme(): void
    {
        config(['metabase.site_url' => 'javascript:alert(1)']);

        $this->expectException(RuntimeException::class);

        $this->service()->signedUrl();
    }

    public function test_signed_url_rejects_site_url_containing_credentials(): void
    {
        config(['metabase.site_url' => 'https://user:secret@metabase.test']);

        $this->expectException(RuntimeException::class);

        $this->service()->signedUrl();
    }

    public function test_signed_url_throws_when_embedding_secret_is_too_short(): void
    {
        config(['metabase.embedding_secret' => 'terlalu-pendek']);

        $this->expectException(RuntimeException::class);

        $this->service()->signedUrl();
    }

    public function test_signed_url_rejects_params_that_are_not_scalar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->signedUrl(['kec' => ['bersarang' => ['lewat']]]);
    }

    public function test_signed_url_rejects_empty_parameter_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->signedUrl(['' => 'Karangasem']);
    }

    public function test_signed_url_accepts_list_of_scalar_params(): void
    {
        $token = $this->extractToken($this->service()->signedUrl(['kec' => ['Karangasem', 'Rendang']]));

        $this->assertSame(['Karangasem', 'Rendang'], array_values((array) $this->decodeToken($token)->params->kec));
    }
}
