<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Nullable for legacy debits. The first reconciliation records the owner.
            $table->unsignedBigInteger('consulting_hours_client_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('consulting_hours_client_id'));
    }
};
