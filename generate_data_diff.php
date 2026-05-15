<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$pdo = DB::connection()->getPdo();

// Tables to generate INSERTs for (clinic_v2 has rows missing in clinic)
// Skipping: sessions, personal_access_tokens, cache, migrations (transient/auto)
$tasks = [
    // [table, id_column or null for composite, where_clinic_only / where_v2_only]
    ['roles',                'id'],
    ['permissions',          'id'],
    ['role_has_permissions', null],   // composite key
    ['model_has_roles',      null],   // composite key
    ['model_has_permissions',null],   // composite key
    ['users',                'id'],
    ['doctor_services',      'id'],
    ['reservation_services', 'id'],
    ['reservations',         'id'],
    ['financials',           'id'],
];

function quoteVal($v) {
    if ($v === null) return 'NULL';
    return "'" . addslashes($v) . "'";
}

function insertRows($pdo, $table, $rows) {
    if (!$rows) return '';
    $first = $rows[0];
    $cols = array_keys($first);
    $quoted_cols = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $lines = [];
    foreach ($rows as $row) {
        $vals = implode(', ', array_map(fn($v) => quoteVal($v), array_values($row)));
        $lines[] = "($vals)";
    }
    return "INSERT INTO `$table` ($quoted_cols) VALUES\n" . implode(",\n", $lines) . ";\n";
}

$output = "-- ============================================================\n";
$output .= "-- Row-level data differences: clinic_v2 → clinic\n";
$output .= "-- Rows present in clinic_v2 but missing in clinic\n";
$output .= "-- Run on the `clinic` database in phpMyAdmin\n";
$output .= "-- ============================================================\n\n";
$output .= "USE `clinic`;\n\n";
$output .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tasks as [$table, $idCol]) {
    if ($idCol) {
        // Get IDs in clinic_v2 not in clinic
        $stmt = $pdo->query("SELECT id FROM `clinic_v2`.`$table` WHERE id NOT IN (SELECT id FROM `clinic`.`$table`)");
        $missingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$missingIds) continue;

        $ids = implode(',', array_map('intval', $missingIds));
        $stmt = $pdo->query("SELECT * FROM `clinic_v2`.`$table` WHERE id IN ($ids) ORDER BY id");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Composite key: get full rows from clinic_v2, insert only those not in clinic
        // Strategy: dump all from clinic_v2 and use INSERT IGNORE
        $stmt = $pdo->query("SELECT * FROM `clinic_v2`.`$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) continue;
    }

    if (!$rows) continue;

    $output .= "-- ------------------------------------------------------------\n";
    $output .= "-- $table (" . count($rows) . " rows)\n";
    $output .= "-- ------------------------------------------------------------\n";

    if ($idCol === null) {
        // Use INSERT IGNORE for composite-key tables
        $first = $rows[0];
        $cols = array_keys($first);
        $quoted_cols = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $lines = [];
        foreach ($rows as $row) {
            $vals = implode(', ', array_map(fn($v) => quoteVal($v), array_values($row)));
            $lines[] = "($vals)";
        }
        $output .= "INSERT IGNORE INTO `$table` ($quoted_cols) VALUES\n" . implode(",\n", $lines) . ";\n\n";
    } else {
        $output .= insertRows($pdo, $table, $rows) . "\n";
    }
}

$output .= "SET FOREIGN_KEY_CHECKS = 1;\n\n";
$output .= "-- Done.\n";

file_put_contents(__DIR__ . '/../clinic_v2_data_diff.sql', $output);
echo "Written to clinic_v2_data_diff.sql\n";
echo "Total size: " . strlen($output) . " bytes\n";
