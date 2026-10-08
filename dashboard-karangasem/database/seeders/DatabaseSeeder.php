<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'vip', 'guard_name' => 'web']);

        $password = config('admin.password') ?: Str::password(16);

        $user = User::firstOrCreate(
            ['email' => config('admin.email')],
            ['name' => 'Administrator', 'password' => Hash::make($password), 'is_active' => true]
        );
        $user->assignRole($admin);

        if (config('admin.password') === null) {
            $this->command?->warn("Admin awal dibuat: {$user->email} / password: {$password} (simpan, tidak ditampilkan lagi)");
        }
    }
}
