<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'doctor.create-purchases',
            'guard_name' => 'web',
        ]);

        $doctorRole = Role::where('name', 'doctor')
            ->where('guard_name', 'web')
            ->first();

        if ($doctorRole && !$doctorRole->hasPermissionTo($permission)) {
            $doctorRole->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'doctor.create-purchases')
            ->where('guard_name', 'web')
            ->first();

        if (!$permission) {
            return;
        }

        Role::where('name', 'doctor')
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->revokePermissionTo($permission));

        $permission->delete();
    }
};
