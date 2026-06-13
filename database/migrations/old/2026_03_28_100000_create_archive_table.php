<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('related_id')->default(0)->index();
            $table->unsignedInteger('version')->default(0)->index();
            $table->char('language', 2)->default('en');
            $table->string('short_name', 32)->nullable()->index();
            $table->unsignedBigInteger('parent_id')->default(0)->index();
            $table->unsignedTinyInteger('type')->default(0)->index()->comment('0: folder, 1: page, 2: file, 3: text, 4: json, 5: xml, 6: url');
            $table->string('archive_link', 200)->nullable();
            $table->unsignedInteger('order')->nullable();
            $table->unsignedInteger('flags')->default(3);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 300);
            $table->string('sub_title', 512)->nullable();
            $table->mediumText('description')->nullable();
            $table->string('extension', 200)->nullable();
            $table->string('application_type', 256)->nullable();
            $table->string('content_type', 128)->nullable();
            $table->unsignedBigInteger('document_type_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedInteger('uploading_stage')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('path', 1024)->nullable();
            $table->text('search_text')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamps();

            $table->unique(['parent_id', 'language', 'title'], 'archive_parent_language_title_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive');
    }
};