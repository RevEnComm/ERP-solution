<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionMap = [
            'dashboard' => ['view'],
            'supplier' => ['view', 'add', 'update', 'delete'],
            'deposit' => ['view', 'add', 'update', 'delete'],
            'category' => ['view', 'add', 'update', 'delete'],
            'brand' => ['view', 'add', 'update', 'delete'],
            'lift' => ['view', 'add', 'update', 'delete'],
            'shop' => ['view', 'add', 'update', 'delete'],
            'sales' => ['view', 'add', 'update', 'delete'],
            'expense' => ['view', 'add', 'update', 'delete'],
            'report' => ['profit-loss'],
            'inventory' => ['view'],
            'role' => ['view', 'add', 'update', 'delete'],
            'user' => ['view', 'add', 'update'],
        ];

        foreach ($permissionMap as $module => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$module}.{$action}",
                    'guard_name' => 'web',
                ]);
            }
        }
    }
}
