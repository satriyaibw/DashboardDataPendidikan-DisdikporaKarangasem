<?php

namespace Tests\Feature;

use App\Http\Middleware\AssignRequestId;
use Tests\TestCase;

/**
 * Uji middleware `AssignRequestId`.
 *
 * Hanya header respons yang diuji. `request_id` SENGAJA tidak pernah
 * boleh masuk ke body halaman atau body JSON: header sudah memberi klien
 * nilai yang sama untuk korelasi, sedangkan memantulkannya di body berarti
 * satu nilai identik ikut ter-cache oleh proxy dan browser.
 */
class RequestIdLoggingTest extends TestCase
{
    public function test_responses_carry_a_request_id_header(): void
    {
        $response = $this->get('/up');

        $response->assertOk();

        $this->assertTrue(
            $response->headers->has(AssignRequestId::HEADER),
            'Setiap respons harus memuat header `'.AssignRequestId::HEADER.'`.',
        );
    }

    public function test_request_id_is_a_uuid(): void
    {
        $requestId = $this->get('/up')->headers->get(AssignRequestId::HEADER);

        $this->assertIsString($requestId);
        $this->assertTrue(
            (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestId),
            "`{$requestId}` bukan UUID v4 yang valid.",
        );
    }

    public function test_each_request_gets_a_distinct_request_id(): void
    {
        $first = $this->get('/up')->headers->get(AssignRequestId::HEADER);
        $second = $this->get('/up')->headers->get(AssignRequestId::HEADER);

        // Kalau identitasnya ikut dipatok dari nilai request, dua request
        // akan memakai UUID yang sama dan seluruh korelasi log menjadi
        // tidak berguna.
        $this->assertNotSame($first, $second);
    }

    public function test_the_header_is_present_even_when_the_response_is_an_error(): void
    {
        // Respons 404 pun harus bisa dikorelasikan: justru request seperti itu
        // yang paling sering dikomplain pengguna.
        $response = $this->get('/halaman-yang-tidak-ada');

        $response->assertNotFound();
        $this->assertTrue($response->headers->has(AssignRequestId::HEADER));
    }

    public function test_the_request_id_is_not_echoed_into_the_response_body(): void
    {
        $response = $this->get('/up');

        $requestId = $response->headers->get(AssignRequestId::HEADER);

        $this->assertIsString($requestId);
        $this->assertStringNotContainsString($requestId, $response->getContent());
    }
}
