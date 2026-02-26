<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user if it doesn't exist
        if (!User::where('email', 'admin@clinic.com')->exists()) {
            User::create([
                'name' => 'System Administrator',
                'email' => 'admin@clinic.com',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]);
        }

        // Create a sample doctor for testing
        if (!User::where('email', 'doctor@clinic.com')->exists()) {
            User::create([
                'name' => 'Dr. John Smith',
                'email' => 'doctor@clinic.com',
                'password' => Hash::make('password'),
                'role' => 'doctor',
                'subscription_start' => now()->toDateString(),
                'subscription_end' => now()->addMonths(12)->toDateString(),
                'is_active' => true,
                'subscription_plan' => 'Premium Yearly',
                'subscription_amount' => 1200.00,
                'notes' => 'Premium subscription - Full access to all features',
            ]);
        }
    }
}
