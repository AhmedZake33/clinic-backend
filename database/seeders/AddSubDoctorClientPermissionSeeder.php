<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AddSubDoctorClientPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'doctor.view-clients',
            'doctor.create-clients',
            'doctor.edit-clients',
            'doctor.delete-clients',
        ];

        $subDoctorRole = Role::firstOrCreate(['name' => 'sub-doctor', 'guard_name' => 'web']);

        foreach ($permissions as $perm) {
            $permission = Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            $subDoctorRole->givePermissionTo($permission);
        }

        $this->command->info('Client permissions assigned to sub-doctor role.');
    }
}
