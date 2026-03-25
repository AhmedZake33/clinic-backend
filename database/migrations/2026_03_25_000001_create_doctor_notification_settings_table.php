<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->unique()->constrained('users')->onDelete('cascade');
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->unsignedInteger('reminder_hours_before')->default(24);
            $table->text('sms_template')->nullable();
            $table->string('whatsapp_template_name')->default('appointment_reminder');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_notification_settings');
    }
};
