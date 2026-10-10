<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);

        $generated = config('admin.password') === null;
        $password = $this->initialPassword();

        $user = User::firstOrCreate(
            ['email' => config('admin.email')],
            ['name' => 'Administrator', 'password' => Hash::make($password), 'is_active' => true]
        );
        $user->assignRole($admin);

        // Password acak hanya ditampilkan sekali: setelah ini ia hanya tersedia
        // sebagai hash, jadi operator wajab menyimpannya sekarang.
        if ($generated) {
            $this->command?->warn("Admin awal dibuat: {$user->email} / password: {$password} (simpan, tidak ditampilkan lagi)");
        }
    }

    /**
     * Password admin awal. Bila ADMIN_PASSWORD diisi tetapi tidak memenuhi
     * kebijakan, seeder gagal tersurat alih-alih membuat akun admin dengan
     * kredensial lemah yang tak terdeteksi.
     *
     * @throws RuntimeException
     */
    protected function initialPassword(): string
    {
        $configured = config('admin.password');

        if ($configured === null || $configured === '') {
            return Str::password(16);
        }

        if (! PasswordPolicy::accepts($configured)) {
            throw new RuntimeException(
                'ADMIN_PASSWORD tidak memenuhi kebijakan password: minimal '
                .PasswordPolicy::MINIMUM_LENGTH.' karakter, memuat huruf besar, huruf kecil, dan angka.'
            );
        }

        return $configured;
    }
}
