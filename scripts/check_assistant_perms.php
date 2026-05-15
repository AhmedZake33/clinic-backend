<?php
require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$assistant = \App\Models\User::where('role', 'assistant')->first();
if (!$assistant) { echo "No assistant found\n"; exit(1); }

echo "Assistant: {$assistant->name} (id={$assistant->id})\n";
echo "Roles: " . implode(', ', $assistant->getRoleNames()->toArray()) . "\n";
$perms = $assistant->getAllPermissions()->pluck('name')->toArray();
echo "Permissions (" . count($perms) . "):\n" . implode("\n", $perms) . "\n";

$doctor = \App\Models\User::where('role', 'doctor')->first();
if ($doctor) {
    echo "\nDoctor: {$doctor->name} (id={$doctor->id})\n";
    echo "Roles: " . implode(', ', $doctor->getRoleNames()->toArray()) . "\n";
    $dperms = $doctor->getAllPermissions()->pluck('name')->toArray();
    echo "Permissions (" . count($dperms) . "):\n" . implode("\n", $dperms) . "\n";
}
