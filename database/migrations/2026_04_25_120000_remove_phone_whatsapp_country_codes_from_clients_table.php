<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'phone_country_code')) {
                $table->dropColumn('phone_country_code');
            }
            if (Schema::hasColumn('clients', 'whatsapp_country_code')) {
                $table->dropColumn('whatsapp_country_code');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'phone_country_code')) {
                $table->string('phone_country_code', 10)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('clients', 'whatsapp_country_code')) {
                $table->string('whatsapp_country_code', 10)->nullable()->after('whatsapp_number');
            }
        });
    }
};
