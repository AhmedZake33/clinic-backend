<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (!Schema::hasColumn('reservations', 'source')) {
                $table->string('source', 30)->default('internal')->after('status');
            }

            if (!Schema::hasColumn('reservations', 'online_booking_ip')) {
                $table->string('online_booking_ip', 45)->nullable()->after('source');
            }

            if (!Schema::hasColumn('reservations', 'online_booking_user_agent')) {
                $table->string('online_booking_user_agent', 500)->nullable()->after('online_booking_ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'online_booking_user_agent')) {
                $table->dropColumn('online_booking_user_agent');
            }
            if (Schema::hasColumn('reservations', 'online_booking_ip')) {
                $table->dropColumn('online_booking_ip');
            }
            if (Schema::hasColumn('reservations', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
