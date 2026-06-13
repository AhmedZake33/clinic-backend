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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('item_name');
            $table->enum('category', ['Medical Supplies','Equipment','Services','Maintenance','Other'])->default('Other');
            $table->integer('quantity')->nullable();
            $table->decimal('amount_paid', 15, 2);
            $table->string('supplier')->nullable();
            $table->enum('payment_method', ['Cash','Card','Bank Transfer'])->default('Cash');
            $table->date('purchase_date');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
