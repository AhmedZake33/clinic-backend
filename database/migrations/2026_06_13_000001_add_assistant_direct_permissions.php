<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
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
            'assistant.complete-reservations',
            'assistant.view-financials',
            'assistant.create-financials',
            'assistant.edit-financials',
            'assistant.delete-financials',
            'assistant.view-purchases',
            'assistant.create-purchases',
            'assistant.edit-purchases',
            'assistant.delete-purchases',
            'assistant.view-waiting-queue',
            'assistant.check-in-patients',
            'assistant.view-assistant-calls',
            'assistant.accept-assistant-calls',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $permissionIds = Permission::whereIn('name', $permissions)->pluck('id')->all();

        User::where('role', 'assistant')->chunkById(100, function ($assistants) use ($permissionIds) {
            foreach ($assistants as $assistant) {
                foreach ($permissionIds as $permissionId) {
                    DB::table('model_has_permissions')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'model_type' => User::class,
                        'model_id' => $assistant->id,
                    ]);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
