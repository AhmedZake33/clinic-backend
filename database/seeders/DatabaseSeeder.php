<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed admin and sample doctor
        $this->call(AdminUserSeeder::class);

        // Ensure sub-doctor role exists
        $this->call(\Database\Seeders\CreateSubDoctorRoleSeeder::class);

        // Create an assistant (will be assigned to doctor later)
        User::create([
            'name' => 'Sarah Johnson',
            'email' => 'assistant@clinic.com',
            'password' => Hash::make('password'),
            'role' => 'assistant',
            'doctor_id' => 2, // Assuming doctor ID is 2
        ]);

        // Create a client user
        User::create([
            'name' => 'Client User',
            'email' => 'client@clinic.com',
            'password' => Hash::make('password'),
            'role' => 'client',
        ]);
    }
}
