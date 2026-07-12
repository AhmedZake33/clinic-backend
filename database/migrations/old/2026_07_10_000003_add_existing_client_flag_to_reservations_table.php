<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (!Schema::hasColumn('reservations', 'online_booking_existing_client')) {
                $table->boolean('online_booking_existing_client')
                    ->default(false)
                    ->after('source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'online_booking_existing_client')) {
                $table->dropColumn('online_booking_existing_client');
            }
        });
    }
};
