<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('doctor_holidays')) {
            return;
        }

        if (!Schema::hasColumn('doctor_holidays', 'recurring_day_of_week')) {
            Schema::table('doctor_holidays', function (Blueprint $table) {
                $table->unsignedTinyInteger('recurring_day_of_week')->nullable()->after('date');
                $table->index(['user_id', 'recurring_day_of_week']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('doctor_holidays')) {
            return;
        }

        if (Schema::hasColumn('doctor_holidays', 'recurring_day_of_week')) {
            Schema::table('doctor_holidays', function (Blueprint $table) {
                $table->dropIndex('doctor_holidays_user_id_recurring_day_of_week_index');
                $table->dropColumn('recurring_day_of_week');
            });
        }
    }
};
