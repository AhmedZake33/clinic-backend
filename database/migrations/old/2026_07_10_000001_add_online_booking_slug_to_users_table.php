<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'booking_slug')) {
                $table->string('booking_slug')->nullable()->unique()->after('max_sub_doctors');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'booking_slug')) {
                $table->dropUnique(['booking_slug']);
                $table->dropColumn('booking_slug');
            }
        });
    }
};
