<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('doctor_availabilities')) {
            return;
        }

        if (!Schema::hasColumn('doctor_availabilities', 'slot_duration_minutes')) {
            Schema::table('doctor_availabilities', function (Blueprint $table) {
                $table->unsignedSmallInteger('slot_duration_minutes')->default(30)->after('end_time');
            });
        }

        DB::table('doctor_availabilities')
            ->whereNull('slot_duration_minutes')
            ->update(['slot_duration_minutes' => 30]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('doctor_availabilities')) {
            return;
        }

        if (Schema::hasColumn('doctor_availabilities', 'slot_duration_minutes')) {
            Schema::table('doctor_availabilities', function (Blueprint $table) {
                $table->dropColumn('slot_duration_minutes');
            });
        }
    }
};
