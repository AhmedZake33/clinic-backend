<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('archive_id')->constrained('archive')->cascadeOnDelete();
            $table->unsignedBigInteger('main_archive_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_updates');
    }
};