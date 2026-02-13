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
        // Create a doctor
        User::create([
            'name' => 'Dr. John Smith',
            'email' => 'doctor@clinic.com',
            'password' => Hash::make('password'),
            'role' => 'doctor',
        ]);

        // Create an assistant
        User::create([
            'name' => 'Sarah Johnson',
            'email' => 'assistant@clinic.com',
            'password' => Hash::make('password'),
            'role' => 'assistant',
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
