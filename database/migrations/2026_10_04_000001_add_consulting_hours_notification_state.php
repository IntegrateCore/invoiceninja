<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('consulting_hours_funded')->default(false);
            $table->timestamp('consulting_hours_alerted_at')->nullable();
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('consulting_hours_custom_field')->nullable();
        });
        // Existing positive balances are funded; ordinary zero-balance clients are not.
        DB::table('clients')->where('consulting_hours_balance', '>', 0)
            ->update(['consulting_hours_funded' => true]);

        // Upgrade saved columns once, retaining their order and every other preference.
        // Users can remove the new column later; it will not be reinserted at runtime.
        DB::table('company_user')->select('id', 'react_settings', 'settings')->orderBy('id')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    $react = json_decode($user->react_settings ?: '{}', true) ?: [];
                    $legacy = json_decode($user->settings ?: '{}', true) ?: [];
                    $columns = $react['react_table_columns']['client'] ?? $legacy['react_table_columns']['client'] ?? null;
                    if (!is_array($columns) || in_array('consulting_hours_balance', $columns, true)) {
                        continue;
                    }
                    $columns[] = 'consulting_hours_balance';
                    $react['react_table_columns']['client'] = $columns;
                    DB::table('company_user')->where('id', $user->id)->update(['react_settings' => json_encode($react)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['consulting_hours_funded', 'consulting_hours_alerted_at']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('consulting_hours_custom_field'));
    }
};
