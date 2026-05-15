<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReservationServicePermissions extends Migration
{
    /**
     * Run the migrations.
     *
     * Create granular permissions for reservation services and assign them
     * to doctor and assistant roles if those roles exist.
     *
     * @return void
     */
    public function up()
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

        // Assign to roles if present
        $roles = ['doctor', 'assistant'];
        foreach ($roles as $r) {
            $role = \Spatie\Permission\Models\Role::where('name', $r)->first();
            if ($role) {
                foreach ($permissions as $p) {
                    if (!$role->hasPermissionTo($p)) $role->givePermissionTo($p);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!class_exists('\Spatie\Permission\Models\Permission')) return;

        $permissions = [
            'reservation-services.view',
            'reservation-services.create',
            'reservation-services.edit',
            'reservation-services.delete',
        ];

        foreach ($permissions as $p) {
            $perm = \Spatie\Permission\Models\Permission::where('name', $p)->first();
            if ($perm) $perm->delete();
        }
    }
}
