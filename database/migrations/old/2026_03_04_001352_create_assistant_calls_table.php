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
        Schema::create('assistant_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assistant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('clinic_id')->comment('doctor_id of the owning doctor (tenant)');
            $table->enum('status', ['pending', 'accepted', 'done'])->default('pending');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'status']);
            $table->index(['doctor_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_calls');
    }
};
