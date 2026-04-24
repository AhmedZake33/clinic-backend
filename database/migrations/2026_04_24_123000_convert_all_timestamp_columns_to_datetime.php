<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ConvertAllTimestampColumnsToDatetime extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $dbName = DB::selectOne('select database() as db')->db;

        $columns = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND DATA_TYPE = ?'
            , [$dbName, 'timestamp']
        );

        foreach ($columns as $col) {
            if ($col->TABLE_NAME === 'migrations') continue;
            $nullable = ($col->IS_NULLABLE === 'YES') ? 'NULL' : 'NOT NULL';
            $sql = "ALTER TABLE `{$col->TABLE_NAME}` MODIFY `{$col->COLUMN_NAME}` DATETIME $nullable";
            DB::statement($sql);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $dbName = DB::selectOne('select database() as db')->db;

        $columns = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND DATA_TYPE = ?'
            , [$dbName, 'datetime']
        );

        foreach ($columns as $col) {
            if ($col->TABLE_NAME === 'migrations') continue;
            $nullable = ($col->IS_NULLABLE === 'YES') ? 'NULL' : 'NOT NULL';
            $sql = "ALTER TABLE `{$col->TABLE_NAME}` MODIFY `{$col->COLUMN_NAME}` TIMESTAMP $nullable";
            DB::statement($sql);
        }
    }
}
