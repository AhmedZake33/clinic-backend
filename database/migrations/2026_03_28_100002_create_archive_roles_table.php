<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained('archive')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->unsignedTinyInteger('access_mode')->default(1);

            $table->unique(['archive_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_roles');
    }
};