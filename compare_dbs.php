<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$pdo = DB::connection()->getPdo();

// Verify both DBs exist
$stmt = $pdo->query("SELECT table_schema, COUNT(*) AS tbl_count FROM information_schema.tables WHERE table_schema IN ('clinic','clinic_v2') GROUP BY table_schema");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $r = array_change_key_case($r, CASE_UPPER);
    echo "DB '{$r['TABLE_SCHEMA']}': {$r['TBL_COUNT']} tables\n";
}
echo "\n";

function getTables($pdo, $db) {
    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = '$db' ORDER BY table_name");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r){ $r = array_change_key_case($r, CASE_UPPER); return $r['TABLE_NAME']; }, $rows);
}

function getColumns($pdo, $db, $table) {
    $stmt = $pdo->query("SELECT column_name, column_type, is_nullable, column_default, extra FROM information_schema.columns WHERE table_schema = '$db' AND table_name = '$table' ORDER BY ordinal_position");
    return array_map(function($r){ return array_change_key_case($r, CASE_UPPER); }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

$t1 = getTables($pdo, 'clinic');
$t2 = getTables($pdo, 'clinic_v2');

$only1 = array_diff($t1, $t2);
$only2 = array_diff($t2, $t1);
$both  = array_intersect($t1, $t2);

echo "=== Tables only in 'clinic' ===\n";
foreach ($only1 as $t) echo "  - $t\n";
if (!$only1) echo "  (none)\n";

echo "\n=== Tables only in 'clinic_v2' ===\n";
foreach ($only2 as $t) echo "  - $t\n";
if (!$only2) echo "  (none)\n";

echo "\n=== Column differences in shared tables ===\n";
foreach ($both as $table) {
    $cols1 = getColumns($pdo, 'clinic', $table);
    $cols2 = getColumns($pdo, 'clinic_v2', $table);

    $map1 = array_column($cols1, null, 'COLUMN_NAME');
    $map2 = array_column($cols2, null, 'COLUMN_NAME');

    $names1 = array_keys($map1);
    $names2 = array_keys($map2);

    $onlyIn1 = array_diff($names1, $names2);
    $onlyIn2 = array_diff($names2, $names1);
    $changed = [];

    foreach (array_intersect($names1, $names2) as $col) {
        $c1 = $map1[$col];
        $c2 = $map2[$col];
        $diffs = [];
        if ($c1['COLUMN_TYPE'] !== $c2['COLUMN_TYPE'])     $diffs[] = "type: {$c1['COLUMN_TYPE']} → {$c2['COLUMN_TYPE']}";
        if ($c1['IS_NULLABLE'] !== $c2['IS_NULLABLE'])     $diffs[] = "nullable: {$c1['IS_NULLABLE']} → {$c2['IS_NULLABLE']}";
        if ($c1['COLUMN_DEFAULT'] !== $c2['COLUMN_DEFAULT']) $diffs[] = "default: ".var_export($c1['COLUMN_DEFAULT'],true)." → ".var_export($c2['COLUMN_DEFAULT'],true);
        if ($c1['EXTRA'] !== $c2['EXTRA'])                 $diffs[] = "extra: {$c1['EXTRA']} → {$c2['EXTRA']}";
        if ($diffs) $changed[$col] = $diffs;
    }

    if ($onlyIn1 || $onlyIn2 || $changed) {
        echo "\n  Table: $table\n";
        foreach ($onlyIn1 as $col) echo "    [-] clinic only:    $col ({$map1[$col]['COLUMN_TYPE']})\n";
        foreach ($onlyIn2 as $col) echo "    [+] clinic_v2 only: $col ({$map2[$col]['COLUMN_TYPE']})\n";
        foreach ($changed as $col => $diffs) {
            echo "    [~] $col: " . implode(', ', $diffs) . "\n";
        }
    }
}

echo "\nDone.\n";
