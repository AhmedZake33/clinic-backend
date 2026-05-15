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
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('financial_id')->after('id')->constrained('financials')->cascadeOnDelete();
            $table->foreignId('doctor_id')->after('financial_id')->constrained('users');
            $table->foreignId('created_by')->after('doctor_id')->constrained('users');
            $table->decimal('amount', 10, 2)->after('created_by');
            $table->enum('payment_method', ['cash', 'card', 'transfer', 'other'])->default('cash')->after('amount');
            $table->text('notes')->nullable()->after('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['financial_id']);
            $table->dropForeign(['doctor_id']);
            $table->dropForeign(['created_by']);
            $table->dropColumn(['financial_id', 'doctor_id', 'created_by', 'amount', 'payment_method', 'notes']);
        });
    }
};
