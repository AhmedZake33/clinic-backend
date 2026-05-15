<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class CreateSubDoctorRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create sub-doctor role if not exists
        Role::firstOrCreate([
            'name' => 'sub-doctor',
            'guard_name' => 'web',
        ]);
    }
}
