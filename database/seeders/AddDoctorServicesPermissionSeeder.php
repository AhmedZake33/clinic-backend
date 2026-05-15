<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AddDoctorServicesPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create the permission if it doesn't exist
        $permission = Permission::firstOrCreate([
            'name'       => 'doctor.view-services',
            'guard_name' => 'web',
        ]);

        // Grant to doctor role
        $doctorRole = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        $doctorRole->givePermissionTo($permission);

        // Grant to sub-doctor role (create it if missing)
        $subDoctorRole = Role::firstOrCreate(['name' => 'sub-doctor', 'guard_name' => 'web']);
        $subDoctorRole->givePermissionTo($permission);

        $this->command->info('doctor.view-services permission assigned to doctor and sub-doctor roles.');
    }
}
