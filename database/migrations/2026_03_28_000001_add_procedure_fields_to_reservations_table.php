<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->text('current_procedures')->nullable()->after('treatment');
            $table->text('procedure_notes')->nullable()->after('current_procedures');
            $table->text('next_procedures')->nullable()->after('procedure_notes');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['current_procedures', 'procedure_notes', 'next_procedures']);
        });
    }
};