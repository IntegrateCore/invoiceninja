<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Company;
use App\Services\IntegrateCore\ConsultingHours;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupConsultingHoursMobile extends Command
{
    protected $signature = 'integratecore:consulting-hours-mobile {company_id : Numeric company ID}';
    protected $description = 'Reserve an unused standard client custom field for consulting hours in the native mobile app';

    public function handle(ConsultingHours $hours): int
    {
        $company = Company::findOrFail((int) $this->argument('company_id'));
        return $company->getConnection()->transaction(function () use ($company, $hours) {
            $company = Company::lockForUpdate()->findOrFail($company->id);
            if ($slot = $hours->mobileSlot($company)) {
                $this->info("Already configured: client{$slot} / custom_value{$slot}.");
                return self::SUCCESS;
            }
            $custom = (array) $company->custom_fields;
            for ($slot = 1; $slot <= 4; $slot++) {
                if (!empty($custom["client{$slot}"])) {
                    continue;
                }
                // Check archived/deleted clients too; their custom values must be preserved.
                if (Client::withTrashed()->withoutEagerLoads()->where('company_id', $company->id)
                    ->whereNotNull("custom_value{$slot}")->where("custom_value{$slot}", '!=', '')->exists()) {
                    continue;
                }
                $custom["client{$slot}"] = ConsultingHours::MOBILE_LABEL;
                $company->custom_fields = (object) $custom;
                $company->consulting_hours_custom_field = $slot;
                $company->save();
                DB::table('company_user')->where('company_id', $company->id)->orderBy('id')
                    ->chunkById(100, function ($users) use ($slot) {
                        foreach ($users as $user) {
                            $settings = json_decode($user->settings ?: '{}', true) ?: [];
                            $columns = $settings['table_columns']['client'] ?? [
                                'number', 'name', 'balance', 'paid_to_date', 'contact_name', 'contact_email', 'last_login_at',
                            ];
                            if (!is_array($columns) || in_array("custom{$slot}", $columns, true)) {
                                continue;
                            }
                            $columns[] = "custom{$slot}";
                            $settings['table_columns']['client'] = $columns;
                            DB::table('company_user')->where('id', $user->id)
                                ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
                        }
                    });
                // Incremental mobile sync must refresh already-downloaded clients.
                Client::withTrashed()->where('company_id', $company->id)->update(['updated_at' => now()]);
                $this->info("Configured client{$slot} / custom_value{$slot}: " . ConsultingHours::MOBILE_LABEL);
                return self::SUCCESS;
            }
            $this->error('No unused client custom field is available. Existing fields and values were preserved.');
            return self::FAILURE;
        });
    }
}
