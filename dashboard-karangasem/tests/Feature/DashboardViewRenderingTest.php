<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Perenderan halaman dashboard dan apa yang boleh bocor ke HTML.
 *
 * Halaman VIP membawa token embed pada atribut `src` iframe. Token itu punya
 * masa berlaku yang sengaja pendek, jadi kebocorannya harus dibatasi pada satu
 * tampilan saja: tidak boleh ada di HTML lain, tidak boleh ada di header, dan
 * tidak boleh tersimpan cache.
 */
class DashboardViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);

        config([
            'metabase.site_url' => 'https://metabase.test',
            'metabase.embedding_secret' => 'test-secret-yang-cukup-panjang-minimal-32-karakter',
            'metabase.public_dashboard_id' => 2,
            'metabase.vip_dashboard_id' => 4,
            'metabase.embed_ttl' => 600,
        ]);
    }

    protected function vipUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
            'expires_at' => Carbon::now()->addDays(10),
        ], $attributes));
        $user->assignRole('vip');

        return $user;
    }

    /**
     * Ambil nilai satu atribut dari tag iframe pada HTML yang dirender.
     */
    protected function iframeAttribute(string $html, string $attribute): ?string
    {
        if (preg_match('/<iframe\b[^>]*\b'.preg_quote($attribute, '/').'="([^"]*)"/i', $html, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public function test_the_public_dashboard_names_its_iframe(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        // Iframe tanpa `title` terbaca sebagai "frame" kosong oleh pembaca
        // layar, sehingga pengguna tidak tahu apa yang mereka lihat.
        $this->assertSame(
            'Dashboard publik data pendidikan Kabupaten Karangasem',
            $this->iframeAttribute($response->getContent(), 'title'),
        );
    }

    public function test_the_vip_dashboard_names_its_iframe(): void
    {
        $html = $this->actingAs($this->vipUser())->get('/vip/dashboard')->getContent();

        $this->assertSame(
            'Dashboard VIP data pendidikan Kabupaten Karangasem',
            $this->iframeAttribute($html, 'title'),
        );
    }

    /**
     * Iframe Metabase berat. Tanpa `loading="lazy"`, halaman memuatnya
     * seketika bersama HTML dan menahan first paint.
     */
    public function test_both_iframes_load_lazily(): void
    {
        $public = $this->get('/')->getContent();
        $vip = $this->actingAs($this->vipUser())->get('/vip/dashboard')->getContent();

        $this->assertSame('lazy', $this->iframeAttribute($public, 'loading'));
        $this->assertSame('lazy', $this->iframeAttribute($vip, 'loading'));
    }

    /**
     * Halaman ini tertanam di origin Metabase. `referrerpolicy` mencegah URL
     * halaman (yang memuat jalur embed) ikut terkirim ke setiap host yang
     * memuat resource.
     */
    public function test_both_iframes_set_a_restrictive_referrer_policy(): void
    {
        $public = $this->get('/')->getContent();
        $vip = $this->actingAs($this->vipUser())->get('/vip/dashboard')->getContent();

        $this->assertSame('strict-origin-when-cross-origin', $this->iframeAttribute($public, 'referrerpolicy'));
        $this->assertSame('strict-origin-when-cross-origin', $this->iframeAttribute($vip, 'referrerpolicy'));
    }

    /**
     * Secret penanda tangan tidak boleh pernah sampai ke browser: siapa pun
     * yang memilikinya bisa membuat token embed sendiri.
     */
    public function test_the_embedding_secret_never_reaches_the_rendered_page(): void
    {
        $secret = (string) config('metabase.embedding_secret');

        $public = $this->get('/')->getContent();
        $vip = $this->actingAs($this->vipUser())->get('/vip/dashboard')->getContent();

        $this->assertStringNotContainsString($secret, $public);
        $this->assertStringNotContainsString($secret, $vip);
    }

    /**
     * Token embed hanya boleh muncul sekali, pada `src` iframe. Munculnya di
     * tempat lain (atribut data, JavaScript, komentar) berarti ada salinan
     * yang tidak ikut berputar bersama refresh.
     */
    public function test_the_embed_token_appears_only_inside_the_iframe_source(): void
    {
        $html = $this->actingAs($this->vipUser())->get('/vip/dashboard')->getContent();

        $this->assertSame(1, preg_match_all('/\/embed\/dashboard\/[A-Za-z0-9._-]+/', $html));
        $this->assertStringContainsString(
            $this->iframeAttribute($html, 'src'),
            $html,
            'Token harus berada pada atribut src iframe.',
        );
    }

    /**
     * Halaman VIP tidak boleh pernah disimpan cache: token yang tersimpan di
     * cache browser atau proxy bisa dipakai ulang setelah kedaluwarsa.
     */
    public function test_the_vip_dashboard_is_never_cached(): void
    {
        $response = $this->actingAs($this->vipUser())->get('/vip/dashboard');

        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_embed_url_endpoint_is_never_cached(): void
    {
        $response = $this->actingAs($this->vipUser())->get('/vip/embed-url');

        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Halaman publik memuat dashboard tanpa token. Kalau token bocor ke sini,
     * embed publik sebenarnya butuh embed bertanda tangan.
     */
    public function test_the_public_dashboard_carries_no_embed_token(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringNotContainsString('/embed/dashboard/', $html);
        $this->assertStringContainsString('/public/dashboard/', $html);
    }

    /**
     * Halaman VIP menampilkan nama akun sendiri; data akun orang lain tidak
     * punya alasan untuk ikut terender.
     */
    public function test_the_vip_dashboard_renders_only_the_viewing_user(): void
    {
        $viewer = $this->vipUser(['name' => 'Siti Aminah']);
        $other = $this->vipUser(['name' => 'Budi Santoso']);

        $html = $this->actingAs($viewer)->get('/vip/dashboard')->getContent();

        $this->assertStringContainsString('Siti Aminah', $html);
        $this->assertStringNotContainsString('Budi Santoso', $html);
    }

    /**
     * Nama akun di header harus di-escape. Nilai mentah dari database yang
     * berisi `<script>` akan menjadi XSS stored bila dibiarkan apa adanya.
     */
    public function test_the_viewer_name_is_html_escaped(): void
    {
        $user = $this->vipUser(['name' => '<script>alert(1)</script>']);

        $html = $this->actingAs($user)->get('/vip/dashboard')->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Token embed berputar lewat endpoint JSON. Halaman tidak boleh menumpahkan
     * nilai lain dari respons itu ke dalam markup.
     */
    public function test_the_refresh_endpoint_is_referenced_without_leaking_a_token_inline(): void
    {
        $html = $this->actingAs($this->vipUser())->get('/vip/dashboard')->getContent();

        // `@json()` meloloskan tanda `/` sebagai `\/`, jadi yang dibandingkan adalah
        // path-nya sendiri, bukan potongan HTML mentah.
        $this->assertStringContainsString('/vip/embed-url', str_replace('\\/', '/', $html));
        $this->assertStringNotContainsString((string) config('metabase.embedding_secret'), $html);
    }
}
