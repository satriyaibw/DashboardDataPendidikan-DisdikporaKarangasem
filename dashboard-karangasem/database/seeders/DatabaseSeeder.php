<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);

        $password = env('ADMIN_PASSWORD') ?: \Illuminate\Support\Str::password(16);

        $user = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            ['name' => 'Administrator', 'password' => Hash::make($password), 'is_active' => true]
        );
        $user->assignRole($admin);

        if (env('ADMIN_PASSWORD') === null) {
            $this->command?->warn("Admin awal dibuat: {$user->email} / password: {$password} (simpan, tidak ditampilkan lagi)");
        }
    }
}
