<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE financials MODIFY COLUMN payment_method ENUM('cash','card','transfer','other','instapay') NOT NULL DEFAULT 'cash'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE financials MODIFY COLUMN payment_method ENUM('cash','card','transfer','other') NOT NULL DEFAULT 'cash'");
    }
};
