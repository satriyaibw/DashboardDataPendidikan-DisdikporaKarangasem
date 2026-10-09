<?php

namespace App\Http\Controllers;

use App\Services\MetabaseEmbedService;
use Illuminate\View\View;

class PublicController extends Controller
{
    /**
     * Halaman publik dengan iframe public embed Metabase (tanpa token).
     */
    public function index(MetabaseEmbedService $metabase): View
    {
        return view('public.dashboard', [
            'embedUrl' => $metabase->publicUrl(),
        ]);
    }
}
