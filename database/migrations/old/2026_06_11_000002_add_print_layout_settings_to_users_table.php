<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('print_clinic_name_position', 20)->default('center')->after('print_primary_color');
            $table->string('print_patient_info_position', 20)->default('top')->after('print_clinic_name_position');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'print_clinic_name_position',
                'print_patient_info_position',
            ]);
        });
    }
};
