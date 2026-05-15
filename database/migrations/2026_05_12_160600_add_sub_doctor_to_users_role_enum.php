<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'sub-doctor' to the users.role enum
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','doctor','assistant','client','sub-doctor') NOT NULL DEFAULT 'client'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'sub-doctor' from the enum (will fail if any users have that role)
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','doctor','assistant','client') NOT NULL DEFAULT 'client'");
    }
};
