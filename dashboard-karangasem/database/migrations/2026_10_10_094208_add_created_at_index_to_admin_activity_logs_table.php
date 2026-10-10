<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `audit:prune` menghapus berdasarkan `created_at`, dan penjadwalan
     * retention (Fase 6). Tanpa indeks khusus, setiap pemangkasan memindai
     * seluruh tabel. Indeks majemuk yang sudah ada (subject_type,
     * subject_id, created_at) dan (actor_id, created_at) tidak dapat melayani
     * predikat `created_at < ?` tanpa memindai seluruh tabel.
     */
    public function up(): void
    {
        Schema::table('admin_activity_logs', function (Blueprint $table) {
            $table->index('created_at', 'admin_activity_logs_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('admin_activity_logs', function (Blueprint $table) {
            $table->dropIndex('admin_activity_logs_created_at_index');
        });
    }
};
