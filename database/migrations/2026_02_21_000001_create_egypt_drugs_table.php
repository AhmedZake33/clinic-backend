<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('egypt_drugs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('form')->nullable()->index();       // Tablet, Capsule, Syrup, etc.
            $table->string('company')->nullable()->index();
            $table->string('category')->nullable()->index();   // Painkiller, Antibiotic, etc.
            $table->timestamps();

            $table->fullText(['name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egypt_drugs');
    }
};
