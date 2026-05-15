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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_doctor_id')->nullable()->after('doctor_id');
            $table->index('parent_doctor_id');
            $table->foreign('parent_doctor_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['parent_doctor_id']);
            $table->dropIndex(['parent_doctor_id']);
            $table->dropColumn('parent_doctor_id');
        });
    }
};
