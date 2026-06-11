<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('print_clinic_name')->nullable()->after('specialization');
            $table->string('print_clinic_phone')->nullable()->after('print_clinic_name');
            $table->string('print_clinic_address')->nullable()->after('print_clinic_phone');
            $table->text('print_header_text')->nullable()->after('print_clinic_address');
            $table->text('print_footer_text')->nullable()->after('print_header_text');
            $table->string('print_primary_color', 7)->nullable()->after('print_footer_text');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'print_clinic_name',
                'print_clinic_phone',
                'print_clinic_address',
                'print_header_text',
                'print_footer_text',
                'print_primary_color',
            ]);
        });
    }
};
