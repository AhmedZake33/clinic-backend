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
        // Update role enum to include admin
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'doctor', 'assistant', 'client') DEFAULT 'client'");

        // Add subscription fields
        Schema::table('users', function (Blueprint $table) {
            $table->date('subscription_start')->nullable()->after('doctor_id');
            $table->date('subscription_end')->nullable()->after('subscription_start');
            $table->boolean('is_active')->default(true)->after('subscription_end');
            $table->string('subscription_plan')->nullable()->after('is_active');
            $table->decimal('subscription_amount', 10, 2)->nullable()->after('subscription_plan');
            $table->text('notes')->nullable()->after('subscription_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['subscription_start', 'subscription_end', 'is_active', 'subscription_plan', 'subscription_amount', 'notes']);
        });

        // Revert role enum
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('doctor', 'assistant', 'client') DEFAULT 'client'");
    }
};