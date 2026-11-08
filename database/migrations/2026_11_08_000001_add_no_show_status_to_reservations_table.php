<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the `no_show` status (patient did not arrive) to reservations.status.
     */
    public function up(): void
    {
        if (!Schema::hasTable('reservations') || !$this->isMySql()) {
            return;
        }

        DB::statement("ALTER TABLE `reservations` MODIFY COLUMN `status` ENUM('pending','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('reservations') || !$this->isMySql()) {
            return;
        }

        // Records that were no-show go back to cancelled so the old enum accepts them
        DB::table('reservations')->where('status', 'no_show')->update(['status' => 'cancelled']);
        DB::statement("ALTER TABLE `reservations` MODIFY COLUMN `status` ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending'");
    }

    private function isMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
