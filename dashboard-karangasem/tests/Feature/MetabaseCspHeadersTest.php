<?php

namespace Tests\Feature;

use App\Http\Middleware\MetabaseCspHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class MetabaseCspHeadersTest extends TestCase
{
    protected function handleRequest(): Response
    {
        return (new MetabaseCspHeaders)->handle(
            Request::create('/'),
            fn (Request $request) => new Response('ok'),
        );
    }

    protected function policy(): string
    {
        return (string) $this->handleRequest()->headers->get('Content-Security-Policy');
    }

    public function test_policy_allows_only_the_configured_metabase_origin_as_frame_source(): void
    {
        config(['metabase.site_url' => 'https://metabase.test']);

        $this->assertSame(
            "frame-src 'self' https://metabase.test; object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
            $this->policy(),
        );
    }

    public function test_policy_keeps_non_default_port_in_metabase_origin(): void
    {
        config(['metabase.site_url' => 'http://localhost:3000']);

        $this->assertStringStartsWith("frame-src 'self' http://localhost:3000;", $this->policy());
    }

    public function test_policy_omits_default_port_from_metabase_origin(): void
    {
        config(['metabase.site_url' => 'https://metabase.test:443']);

        $this->assertStringStartsWith("frame-src 'self' https://metabase.test;", $this->policy());
    }

    public function test_policy_falls_back_to_self_when_site_url_is_invalid(): void
    {
        config(['metabase.site_url' => 'metabase.test']);

        $this->assertSame(
            "frame-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
            $this->policy(),
        );
    }

    public function test_policy_falls_back_to_self_when_site_url_uses_unsupported_scheme(): void
    {
        config(['metabase.site_url' => 'javascript:alert(1)']);

        $this->assertStringStartsWith("frame-src 'self';", $this->policy());
    }

    public function test_policy_falls_back_to_self_when_site_url_is_missing(): void
    {
        config(['metabase.site_url' => null]);

        $this->assertStringStartsWith("frame-src 'self';", $this->policy());
    }
}
