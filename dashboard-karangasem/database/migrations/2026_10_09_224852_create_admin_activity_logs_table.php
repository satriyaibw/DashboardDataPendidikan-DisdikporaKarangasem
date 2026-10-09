<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak audit aktivitas admin terhadap akun VIP (buat/ubah/hapus).
     *
     * Tabel ini sengaja hanya menyimpan perubahan non-sensitif: nilai
     * password dan token tidak pernah ditulis ke sini. Penyaringan kolom
     * dilakukan di App\Services\AdminActivityLogger (whitelist).
     */
    public function up(): void
    {
        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();
            // Admin pelaku. nullOnDelete supaya jejak audit tetap ada bila
            // akun admin yang melakukan aksi kemudian dihapus.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // Mis. user.created, user.updated, user.deleted.
            $table->string('action');
            // Objek yang dikenai (polymorphic) agar pola sama bisa dipakai
            // untuk model lain di kemudian hari.
            $table->nullableMorphs('subject');
            $table->text('description')->nullable();
            // Perubahan non-sensitif saja, dalam bentuk {"before": ..., "after": ...}.
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'admin_activity_logs_subject_index');
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
    }
};
