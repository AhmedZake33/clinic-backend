<?php
// scripts/create_test_users.php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\Client;
use App\Models\Reservation;

$doctor = User::firstOrCreate(
    ['email' => 'test_doctor@example.com'],
    ['name' => 'Test Doctor', 'password' => 'password', 'role' => 'doctor', 'is_active' => 1, 'subscription_end' => now()->addYear()]
);
$assistant = User::firstOrCreate(
    ['email' => 'test_assistant@example.com'],
    ['name' => 'Test Assistant', 'password' => 'password', 'role' => 'assistant', 'doctor_id' => $doctor->id, 'is_active' => 1, 'subscription_end' => now()->addYear()]
);

$roleDoc = Role::firstOrCreate(['name' => 'doctor']);
$roleAs = Role::firstOrCreate(['name' => 'assistant']);
$perms = ['reservation-services.view','reservation-services.create','reservation-services.edit','reservation-services.delete'];
foreach ($perms as $p) { Permission::firstOrCreate(['name' => $p]); }
$roleDoc->syncPermissions($perms);
$roleAs->syncPermissions($perms);
$doctor->assignRole('doctor');
$assistant->assignRole('assistant');

$client = Client::firstOrCreate(['phone' => '0000000000'], ['name' => 'Test Client', 'doctor_id' => $doctor->id, 'created_by' => $doctor->id]);
$reservation = Reservation::firstOrCreate([
    'doctor_id' => $doctor->id,
    'client_id' => $client->id,
    'appointment_date' => now()->addDay()
], ['status' => 'pending', 'created_by' => $doctor->id]);

$tokenDoc = $doctor->createToken('test')->plainTextToken;
$tokenAs = $assistant->createToken('test')->plainTextToken;

echo "DOCTOR_TOKEN:$tokenDoc\nASSISTANT_TOKEN:$tokenAs\nRESERVATION_ID:".$reservation->id."\n";
