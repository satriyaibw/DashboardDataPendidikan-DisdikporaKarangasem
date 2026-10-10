<?php

namespace Tests\Unit;

use App\Services\MetabaseOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `SecurityHeadersTest` sudah membuktikan origin berbahaya ditolak lewat
 * dampaknya pada header `Permissions-Policy`, dan sudah menguji URL valid
 * yang umum. Test di sini menguji `MetabaseOrigin` sebagai kelas: apa yang
 * dikembalikan persisnya, termasuk kasus yang tidak pernah muncul sebagai
 * header pada test tersebut.
 */
class MetabaseOriginTest extends TestCase
{
    #[DataProvider('validOrigins')]
    public function test_it_normalizes_a_trusted_site_url(?string $siteUrl, ?string $expected): void
    {
        $this->assertSame($expected, MetabaseOrigin::resolve($siteUrl));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function validOrigins(): array
    {
        return [
            'https sederhana' => ['https://mb.test', 'https://mb.test'],
            'port default https dibuang' => ['https://mb.test:443', 'https://mb.test'],
            'port default http dibuang' => ['http://mb.test:80', 'http://mb.test'],
            'port non-default dipertahankan' => ['http://localhost:3000', 'http://localhost:3000'],
            'trailing slash dibuang' => ['https://mb.test/', 'https://mb.test'],
            'path dibuang' => ['https://mb.test/sub/path', 'https://mb.test'],
            'query dibuang' => ['https://mb.test/?a=b', 'https://mb.test'],
            'huruf besar pada host dinormalkan' => ['https://MB.Test', 'https://mb.test'],
            'huruf besar pada skema' => ['HTTPS://mb.test', 'https://mb.test'],
            'ipv6 kurung siku dipertahankan' => ['https://[::1]:3000', 'https://[::1]:3000'],
            'ipv6 tanpa port' => ['https://[2001:db8::1]', 'https://[2001:db8::1]'],
            'spasi di sekitar ditangkas' => ['  https://mb.test  ', 'https://mb.test'],
            'host ip v4' => ['https://127.0.0.1:3000', 'https://127.0.0.1:3000'],
            'label dipisah strip' => ['https://a-b.mb.test', 'https://a-b.mb.test'],

            'kosong ditolak' => ['', null],
            'hanya spasi ditolak' => ['   ', null],
            'null ditolak' => [null, null],
            'tanpa skema ditolak' => ['mb.test', null],
            'skema javascript ditolak' => ['javascript:alert(1)', null],
            'skema data ditolak' => ['data:text/html,<script>', null],
            'skema file ditolak' => ['file:///etc/passwd', null],
            'tanpa host ditolak' => ['https://', null],
            'kredensial ditolak' => ['https://user:pass@mb.test', null],
            'userinfo tanpa password ditolak' => ['https://user@mb.test', null],
            'port tidak valid ditolak' => ['https://mb.test:notaport', null],
            'ipv6 rusak ditolak' => ['https://[not-ipv6]', null],
            'ipv6 tanpa kurung siku ditolak' => ['https://::1', null],
            'label hostname terlalu panjang ditolak' => [
                'https://'.str_repeat('a', 64).'.test', null,
            ],
            'hostname terlalu panjang ditolak' => [
                'https://'.implode('.', array_fill(0, 30, str_repeat('a', 10))).'.test', null,
            ],
        ];
    }

    /**
     * Pemanggil harus mengganti kebijakan menjadi fail-closed, bukan
     * menebak. `null` itulah sinyalnya, sehingga nilai ini tidak boleh
     * pernah berupa string kosong yang lolos validasi downstream.
     */
    public function test_it_never_returns_an_empty_origin_for_rejected_urls(): void
    {
        foreach (['javascript:alert(1)', 'https://user:pass@mb.test', '', 'mb.test'] as $siteUrl) {
            $origin = MetabaseOrigin::resolve($siteUrl);

            $this->assertNull($origin, sprintf('Origin tidak seharusnya diterima: %s', $siteUrl));
        }
    }

    public function test_it_reads_the_site_url_from_configuration(): void
    {
        config(['metabase.site_url' => 'http://localhost:3000/']);

        $this->assertSame('http://localhost:3000', MetabaseOrigin::fromConfiguration());
    }

    public function test_it_returns_null_when_the_configured_site_url_is_untrusted(): void
    {
        config(['metabase.site_url' => 'javascript:alert(1)']);

        $this->assertNull(MetabaseOrigin::fromConfiguration());
    }
}
