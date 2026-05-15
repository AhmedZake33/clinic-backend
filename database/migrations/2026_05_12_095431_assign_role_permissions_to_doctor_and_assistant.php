<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $doctorRole    = Role::firstOrCreate(['name' => 'doctor',    'guard_name' => 'web']);
        $assistantRole = Role::firstOrCreate(['name' => 'assistant', 'guard_name' => 'web']);
        $clientRole    = Role::firstOrCreate(['name' => 'client',    'guard_name' => 'web']);
        $adminRole     = Role::firstOrCreate(['name' => 'admin',     'guard_name' => 'web']);

        $rsPerms = Permission::where('name', 'like', 'reservation-services.%')->get();

        // Give every doctor.* + reservation-services.* permission to the doctor role
        $doctorRole->syncPermissions(
            Permission::where('name', 'like', 'doctor.%')->get()->merge($rsPerms)
        );

        // Give every assistant.* + reservation-services.* permission to the assistant role
        $assistantRole->syncPermissions(
            Permission::where('name', 'like', 'assistant.%')->get()->merge($rsPerms)
        );

        // Give client.* permissions to the client role
        $clientRole->syncPermissions(
            Permission::where('name', 'like', 'client.%')->get()
        );

        // Give all permissions to admin role
        $adminRole->syncPermissions(Permission::all());
    }

    public function down(): void
    {
        // Cannot safely reverse a sync — do nothing
    }
};
