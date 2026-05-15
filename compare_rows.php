<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$pdo = DB::connection()->getPdo();

// Get all shared tables
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'clinic' ORDER BY table_name");
$tables = array_map(function($r){ $r = array_change_key_case($r, CASE_UPPER); return $r['TABLE_NAME']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== Row count comparison: clinic vs clinic_v2 ===\n\n";
printf("%-35s %10s %10s %10s\n", "Table", "clinic", "clinic_v2", "Diff");
echo str_repeat('-', 70) . "\n";

$totalDiff = 0;
foreach ($tables as $table) {
    $c1 = $pdo->query("SELECT COUNT(*) FROM `clinic`.`$table`")->fetchColumn();
    $c2 = $pdo->query("SELECT COUNT(*) FROM `clinic_v2`.`$table`")->fetchColumn();
    $diff = (int)$c2 - (int)$c1;
    $totalDiff += abs($diff);
    $flag = $diff !== 0 ? ' <<<' : '';
    printf("%-35s %10d %10d %+10d%s\n", $table, $c1, $c2, $diff, $flag);
}

echo str_repeat('-', 70) . "\n";
printf("%-35s %10s %10s %10d\n", "TOTAL DIFF (absolute)", '', '', $totalDiff);

// For tables with differences, show which IDs exist in one but not the other
echo "\n=== Missing rows by ID (tables with differences) ===\n";

foreach ($tables as $table) {
    $c1 = (int)$pdo->query("SELECT COUNT(*) FROM `clinic`.`$table`")->fetchColumn();
    $c2 = (int)$pdo->query("SELECT COUNT(*) FROM `clinic_v2`.`$table`")->fetchColumn();

    if ($c1 === $c2) continue;

    // Check if table has an `id` column
    $hasId = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='clinic' AND table_name='$table' AND column_name='id'")->fetchColumn();
    if (!$hasId) {
        echo "\n  Table: $table (no `id` column, skipping detail)\n";
        continue;
    }

    echo "\n  Table: $table ($c1 in clinic, $c2 in clinic_v2)\n";

    // IDs in clinic but not clinic_v2
    $stmt = $pdo->query("SELECT id FROM `clinic`.`$table` WHERE id NOT IN (SELECT id FROM `clinic_v2`.`$table`) LIMIT 20");
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) echo "    In clinic only (IDs): " . implode(', ', $ids) . (count($ids)==20?' ...':'') . "\n";

    // IDs in clinic_v2 but not clinic
    $stmt = $pdo->query("SELECT id FROM `clinic_v2`.`$table` WHERE id NOT IN (SELECT id FROM `clinic`.`$table`) LIMIT 20");
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) echo "    In clinic_v2 only (IDs): " . implode(', ', $ids) . (count($ids)==20?' ...':'') . "\n";
}

echo "\nDone.\n";
