<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ChangePersonalAccessTokensTimestampsToDatetime extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Use raw statements to avoid requiring doctrine/dbal for column modifications
        if (Schema::hasTable('personal_access_tokens')) {
            DB::statement("ALTER TABLE `personal_access_tokens` 
                MODIFY `last_used_at` DATETIME NULL,
                MODIFY `expires_at` DATETIME NULL,
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
        if (Schema::hasTable('personal_access_tokens')) {
            DB::statement("ALTER TABLE `personal_access_tokens` 
                MODIFY `last_used_at` TIMESTAMP NULL,
                MODIFY `expires_at` TIMESTAMP NULL,
                MODIFY `created_at` TIMESTAMP NOT NULL,
                MODIFY `updated_at` TIMESTAMP NOT NULL");
        }
    }
}
