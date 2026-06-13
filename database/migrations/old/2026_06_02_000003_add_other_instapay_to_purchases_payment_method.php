<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `purchases` MODIFY COLUMN `payment_method` ENUM('Cash', 'Card', 'Bank Transfer', 'Other', 'InstaPay') NOT NULL DEFAULT 'Cash'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `purchases` MODIFY COLUMN `payment_method` ENUM('Cash', 'Card', 'Bank Transfer') NOT NULL DEFAULT 'Cash'");
    }
};
