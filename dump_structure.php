<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$pdo = DB::connection()->getPdo();

// Get CREATE TABLE for transactions in clinic_v2
$stmt = $pdo->query("SHOW CREATE TABLE `clinic_v2`.`transactions`");
$row = $stmt->fetch(PDO::FETCH_NUM);
echo "-- transactions table\n";
echo $row[1] . ";\n\n";

// Get exact column defs for changed columns
echo "-- financials: reservation_service_id\n";
$stmt = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
    FROM information_schema.columns
    WHERE table_schema='clinic_v2' AND table_name='financials'
    AND column_name IN ('reservation_service_id','voided','payment_method')");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $r = array_change_key_case($r, CASE_UPPER);
    echo "{$r['COLUMN_NAME']}: {$r['COLUMN_TYPE']} | nullable={$r['IS_NULLABLE']} | default=".var_export($r['COLUMN_DEFAULT'],true)." | extra={$r['EXTRA']}\n";
}

echo "\n-- users: new columns\n";
$stmt = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, ORDINAL_POSITION, COLUMN_COMMENT
    FROM information_schema.columns
    WHERE table_schema='clinic_v2' AND table_name='users'
    AND column_name IN ('parent_doctor_id','max_sub_doctors','role')
    ORDER BY ORDINAL_POSITION");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $r = array_change_key_case($r, CASE_UPPER);
    echo "{$r['COLUMN_NAME']} (pos {$r['ORDINAL_POSITION']}): {$r['COLUMN_TYPE']} | nullable={$r['IS_NULLABLE']} | default=".var_export($r['COLUMN_DEFAULT'],true)." | extra={$r['EXTRA']}\n";
}

// Also get column ordering context for AFTER clause
echo "\n-- users column order\n";
$stmt = $pdo->query("SELECT ORDINAL_POSITION, COLUMN_NAME FROM information_schema.columns WHERE table_schema='clinic_v2' AND table_name='users' ORDER BY ORDINAL_POSITION");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $r = array_change_key_case($r, CASE_UPPER);
    echo "{$r['ORDINAL_POSITION']}: {$r['COLUMN_NAME']}\n";
}

echo "\n-- financials column order\n";
$stmt = $pdo->query("SELECT ORDINAL_POSITION, COLUMN_NAME FROM information_schema.columns WHERE table_schema='clinic_v2' AND table_name='financials' ORDER BY ORDINAL_POSITION");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $r = array_change_key_case($r, CASE_UPPER);
    echo "{$r['ORDINAL_POSITION']}: {$r['COLUMN_NAME']}\n";
}
