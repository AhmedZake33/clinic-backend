<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define permissions grouped by module
        // Section-prefixed permissions ensure each nav tab is uniquely controlled
        $permissions = [
            // ── Assistant section ──
            'assistant.view-dashboard',
            'assistant.view-clients',
            'assistant.create-clients',
            'assistant.edit-clients',
            'assistant.delete-clients',
            'assistant.view-reservations',
            'assistant.create-reservations',
            'assistant.edit-reservations',
            'assistant.delete-reservations',
            'assistant.confirm-reservations',
            'assistant.view-financials',
            'assistant.create-financials',
            'assistant.edit-financials',
            'assistant.delete-financials',
            'assistant.view-reports',
            'assistant.export-reports',
            'assistant.view-waiting-queue',
            'assistant.check-in-patients',
            'assistant.view-assistant-calls',
            'assistant.accept-assistant-calls',

            // ── Doctor section ──
            'doctor.view-dashboard',
            'doctor.view-clients',
            'doctor.create-clients',
            'doctor.edit-clients',
            'doctor.delete-clients',
            'doctor.view-reservations',
            'doctor.edit-reservations',
            'doctor.delete-reservations',
            'doctor.complete-reservations',
            'doctor.view-schedule',
            'doctor.edit-schedule',
            'doctor.view-financials',
            'doctor.view-reports',
            'doctor.export-reports',
            'doctor.view-waiting-queue',
            'doctor.view-assistants',
            'doctor.create-assistants',
            'doctor.edit-assistants',
            'doctor.delete-assistants',
            'doctor.view-drugs',
            'doctor.generate-prescriptions',
            'doctor.create-assistant-calls',
            'doctor.view-assistant-calls',

            // ── Admin section ──
            'admin.view-dashboard',
            'admin.view-doctors',
            'admin.create-doctors',
            'admin.edit-doctors',
            'admin.delete-doctors',
            'admin.view-subscriptions',
            'admin.view-roles',
            'admin.create-roles',
            'admin.edit-roles',
            'admin.delete-roles',
            'admin.view-permissions',
            'admin.assign-permissions',

            // ── Client section ──
            'client.view-dashboard',
            'client.view-reservations',
        ];

        // Create all permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles and assign permissions
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::all());

        $doctorRole = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        $doctorRole->syncPermissions([
            'doctor.view-dashboard',
            'doctor.view-clients',
            'doctor.create-clients',
            'doctor.edit-clients',
            'doctor.delete-clients',
            'doctor.view-reservations',
            'doctor.edit-reservations',
            'doctor.delete-reservations',
            'doctor.complete-reservations',
            'doctor.view-schedule',
            'doctor.edit-schedule',
            'doctor.view-financials',
            'doctor.view-reports',
            'doctor.export-reports',
            'doctor.view-waiting-queue',
            'doctor.view-assistants',
            'doctor.create-assistants',
            'doctor.edit-assistants',
            'doctor.delete-assistants',
            'doctor.view-drugs',
            'doctor.generate-prescriptions',
            'doctor.create-assistant-calls',
            'doctor.view-assistant-calls',
        ]);

        $assistantRole = Role::firstOrCreate(['name' => 'assistant', 'guard_name' => 'web']);
        $assistantRole->syncPermissions([
            'assistant.view-dashboard',
            'assistant.view-clients',
            'assistant.create-clients',
            'assistant.edit-clients',
            'assistant.view-reservations',
            'assistant.create-reservations',
            'assistant.edit-reservations',
            'assistant.delete-reservations',
            'assistant.confirm-reservations',
            'assistant.view-financials',
            'assistant.create-financials',
            'assistant.edit-financials',
            'assistant.delete-financials',
            'assistant.view-reports',
            'assistant.export-reports',
            'assistant.view-waiting-queue',
            'assistant.check-in-patients',
            'assistant.view-assistant-calls',
            'assistant.accept-assistant-calls',
        ]);

        $clientRole = Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $clientRole->syncPermissions([
            'client.view-dashboard',
            'client.view-reservations',
        ]);

        // Assign Spatie roles to existing users based on their 'role' column
        User::chunk(100, function ($users) {
            foreach ($users as $user) {
                if ($user->role && !$user->hasRole($user->role)) {
                    $user->assignRole($user->role);
                }
            }
        });
    }
}
