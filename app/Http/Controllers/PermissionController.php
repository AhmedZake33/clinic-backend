<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /**
     * List all permissions, optionally grouped.
     */
    public function index(Request $request)
    {
        $permissions = Permission::all();

        if ($request->query('grouped')) {
            $grouped = [];
            foreach ($permissions as $perm) {
                // Extract group from permission name, e.g. "view-clients" → "clients"
                $parts = explode('-', $perm->name, 2);
                $group = $parts[1] ?? $parts[0];
                $grouped[$group][] = [
                    'id' => $perm->id,
                    'name' => $perm->name,
                ];
            }
            return response()->json($grouped);
        }

        return response()->json($permissions->map(function ($perm) {
            return [
                'id' => $perm->id,
                'name' => $perm->name,
            ];
        }));
    }

    /**
     * Create a new permission.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name',
        ]);

        $permission = Permission::create([
            'name' => $request->name,
            'guard_name' => 'web',
        ]);

        return response()->json([
            'id' => $permission->id,
            'name' => $permission->name,
        ], 201);
    }

    /**
     * Delete a permission.
     */
    public function destroy(Permission $permission)
    {
        $permission->delete();

        return response()->json(['message' => 'Permission deleted successfully.']);
    }

    /**
     * Return doctor-scoped permissions (doctor.*)
     */
    public function doctorPermissions()
    {
        $perms = Permission::where('name', 'like', 'doctor.%')->get()->map(function ($p) {
            return ['id' => $p->id, 'name' => $p->name];
        });
        return response()->json($perms);
    }
}
