<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'whatsapp_reminder_sent_at')) {
                $table->dateTime('whatsapp_reminder_sent_at')->nullable()->after('checked_in_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'whatsapp_reminder_sent_at')) {
                $table->dropColumn('whatsapp_reminder_sent_at');
            }
        });
    }
};
