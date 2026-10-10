<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::findOrCreate('view orders', 'web');
        $role = Role::findOrCreate('order-reader', 'web');
        $role->givePermissionTo($permission);

        $user = User::firstOrCreate(['email' => 'a@example.test'], [
            'name' => 'Tenant A', 'tenant_id' => 'tenant-a', 'password' => Hash::make('secret'),
        ]);
        $user->assignRole($role);

        User::firstOrCreate(['email' => 'b@example.test'], [
            'name' => 'Tenant B', 'tenant_id' => 'tenant-b', 'password' => Hash::make('secret'),
        ]);

        $direct = User::firstOrCreate(['email' => 'direct@example.test'], [
            'name' => 'Direct permission', 'tenant_id' => 'tenant-a', 'password' => Hash::make('secret'),
        ]);
        $direct->givePermissionTo($permission);
    }
}
