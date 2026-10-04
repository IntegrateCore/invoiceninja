<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_file_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('client_id')->unique();
            $table->string('folder', 191)->unique();
            $table->timestamps();
        });
        Schema::create('client_file_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('document_id')->unique();
            $table->unsignedInteger('client_id')->index();
            $table->string('original_disk');
            $table->text('original_url');
            $table->text('library_path');
            $table->string('sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_file_migrations');
        Schema::dropIfExists('client_file_folders');
    }
};
