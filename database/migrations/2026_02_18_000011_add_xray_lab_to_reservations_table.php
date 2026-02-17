<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->boolean('requires_xray')->default(false)->after('treatment');
            $table->text('xray_notes')->nullable()->after('requires_xray');
            $table->boolean('requires_lab')->default(false)->after('xray_notes');
            $table->text('lab_notes')->nullable()->after('requires_lab');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['requires_xray', 'xray_notes', 'requires_lab', 'lab_notes']);
        });
    }
};
