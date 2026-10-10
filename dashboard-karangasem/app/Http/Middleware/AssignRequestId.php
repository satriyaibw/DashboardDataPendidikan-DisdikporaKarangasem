<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memberi setiap request identitas tunggal yang ikut di semua entri log.
 *
 * Tanpa identitas ini, satu keluhan pengguna ("halaman dashboard tidak
 * muncul kemarin") tidak dapat ditelusuri ke satu request tertentu di antara
 * ribuan baris log. Header `Request-Id` pada respons melakukan dua hal
 * sekaligus: membuat laporan bisa menyebut UUID-nya, dan memberi tahu klien
 * angka mana yang boleh dicari di log.
 *
 * Yang SENGAJA tidak pernah dicatat: body request, header Authorization,
 * query string yang memuat token, dan nilai `METABASE_EMBEDDING_SECRET`.
 * Hanya UUID acak yang dimasukkan — nilai yang sudah ada di request tidak
 * pernah dibaca, sehingga middleware ini tidak mungkin membocorkan rahasia
 * meski secret someday bocor lewat request.
 */
class AssignRequestId
{
    /**
     * Nama header respons yang memuat identitas request.
     */
    public const HEADER = 'Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();

        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
