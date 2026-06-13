<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-Tenant Architecture:
     * - Each assistant belongs to a specific doctor (doctor_id on users)
     * - Each client/patient belongs to a specific doctor (doctor_id on clients)
     * - Reservations and financials already have doctor_id
     */
    public function up(): void
    {
        // Add doctor_id to users table (for assistants to be linked to their doctor)
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('doctor_id')
                ->nullable()
                ->after('role')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Add doctor_id to clients table (each patient belongs to a doctor)
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('doctor_id')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('doctor_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['doctor_id']);
            $table->dropColumn('doctor_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['doctor_id']);
            $table->dropColumn('doctor_id');
        });
    }
};
