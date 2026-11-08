<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            // The clinic owner (doctor) the closing belongs to
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->date('closing_date');
            $table->foreignId('closed_by')->constrained('users');
            $table->unsignedInteger('transactions_count')->default(0);
            $table->decimal('cash_in', 12, 2)->default(0);          // cash payments received
            $table->decimal('cash_purchases', 12, 2)->default(0);   // cash paid out for purchases
            $table->decimal('expected_cash', 12, 2)->default(0);    // cash_in - cash_purchases
            $table->decimal('counted_cash', 12, 2)->default(0);     // what is physically in the drawer
            $table->decimal('difference', 12, 2)->default(0);       // counted - expected
            $table->json('totals')->nullable();                     // snapshot per payment method
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['doctor_id', 'closing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closings');
    }
};
