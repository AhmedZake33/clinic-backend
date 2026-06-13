<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'specialization')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('specialization')->nullable()->after('whatsapp_number');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'specialization')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('specialization');
        });
    }
};
