<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_file_folder_tenants', function (Blueprint $table) {
            $table->id();
            $table->string('folder', 191)->unique();
            $table->unsignedInteger('company_id')->index();
            $table->timestamps();
        });
        \Illuminate\Support\Facades\DB::table('client_file_folders')->orderBy('id')->chunk(100, function ($mappings) {
            foreach ($mappings as $mapping) {
                \Illuminate\Support\Facades\DB::table('client_file_folder_tenants')->insertOrIgnore([
                    'folder' => $mapping->folder, 'company_id' => $mapping->company_id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        Schema::create('client_file_document_references', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('document_id')->unique();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('client_id')->index();
            $table->unsignedInteger('documentable_id');
            $table->string('documentable_type');
            $table->text('library_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_file_document_references');
        Schema::dropIfExists('client_file_folder_tenants');
    }
};
