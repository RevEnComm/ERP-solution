<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Profit & Loss used to ride on `sales.view`; it now has its own permission
     * so it can be granted separately. Only the admin role gets it here — other
     * roles are given it from Role Management. A migration rather than a seeder
     * re-run, because RoleSeeder syncs permissions and would undo role edits.
     */
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'report.profit-loss',
            'guard_name' => 'web',
        ]);

        Role::where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('name', 'report.profit-loss')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
