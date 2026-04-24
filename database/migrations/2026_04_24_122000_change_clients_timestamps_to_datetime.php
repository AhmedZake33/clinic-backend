<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ChangeClientsTimestampsToDatetime extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('clients')) {
            DB::statement("ALTER TABLE `clients` 
                MODIFY `created_at` DATETIME NOT NULL,
                MODIFY `updated_at` DATETIME NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('clients')) {
            DB::statement("ALTER TABLE `clients` 
                MODIFY `created_at` TIMESTAMP NOT NULL,
                MODIFY `updated_at` TIMESTAMP NOT NULL");
        }
    }
}
