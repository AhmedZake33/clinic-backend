<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReservationServiceFkToFinancials extends Migration
{
    public function up()
    {
        Schema::table('financials', function (Blueprint $table) {
            $table->unsignedBigInteger('reservation_service_id')->nullable()->after('reservation_id');
            $table->foreign('reservation_service_id')->references('id')->on('reservation_services')->nullOnDelete();
            $table->boolean('voided')->default(false)->after('notes');
        });
    }

    public function down()
    {
        Schema::table('financials', function (Blueprint $table) {
            if (Schema::hasColumn('financials', 'reservation_service_id')) {
                $table->dropForeign(['reservation_service_id']);
                $table->dropColumn('reservation_service_id');
            }
            if (Schema::hasColumn('financials', 'voided')) {
                $table->dropColumn('voided');
            }
        });
    }
}
