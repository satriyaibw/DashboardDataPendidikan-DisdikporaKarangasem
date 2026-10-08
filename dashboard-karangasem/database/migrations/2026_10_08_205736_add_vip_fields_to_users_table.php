<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->string('vip_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['expires_at', 'is_active', 'vip_notes']);
        });
    }
};
