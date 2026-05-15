<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class AssignReservationServicePermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (!class_exists('\Spatie\Permission\Models\Permission')) return;

        $permissions = [
            'reservation-services.view',
            'reservation-services.create',
            'reservation-services.edit',
            'reservation-services.delete',
        ];

        foreach ($permissions as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p]);
        }

        $roles = ['doctor', 'assistant', 'sub-doctor'];
        foreach ($roles as $r) {
            $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => $r]);
            $role->givePermissionTo($permissions);
        }

        // Optionally give current assistant / sub-doctor users the permission set
        $assistantUsers = User::role('assistant')->get();
        foreach ($assistantUsers as $u) {
            $u->givePermissionTo($permissions);
        }

        $subDoctorUsers = User::role('sub-doctor')->get();
        foreach ($subDoctorUsers as $u) {
            $u->givePermissionTo($permissions);
        }
    }
}
