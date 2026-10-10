<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom untuk multi-factor authentication (TOTP) panel admin.
     *
     * Kedua kolom menyimpan rahasia ber-nilai sensitif, jadi hanya boleh
     * diisi/dibaca oleh fitur MFA Filament dan tidak pernah ikut ke
     * response JSON maupun log.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable();
            // Kode pemulihan (recovery codes) digunakan bila perangkat TOTP
            // hilang; perlu ruang lebih besar karena disimpan sebagai JSON.
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'app_authentication_secret',
                'app_authentication_recovery_codes',
            ]);
        });
    }
};
