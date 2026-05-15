<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AddSubDoctorFinancialPurchasePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'doctor.view-financials',
            'doctor.view-purchases',
        ];

        $subDoctorRole = Role::firstOrCreate(['name' => 'sub-doctor', 'guard_name' => 'web']);

        foreach ($permissions as $perm) {
            $permission = Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            $subDoctorRole->givePermissionTo($permission);
        }

        $this->command->info('Financial and purchase view permissions assigned to sub-doctor role.');
    }
}
