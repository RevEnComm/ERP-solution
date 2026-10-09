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
     * Profit and cost on the Sales Report used to come with `sales.view`; they now
     * sit behind their own permission. Only the admin role gets it here — other
     * roles are given it from Role Management. A migration rather than a seeder
     * re-run, because RoleSeeder syncs permissions and would undo role edits.
     */
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'sales.profit',
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
        Permission::where('name', 'sales.profit')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
