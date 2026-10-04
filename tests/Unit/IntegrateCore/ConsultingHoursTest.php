<?php

namespace Tests\Unit\IntegrateCore;

use App\Console\Commands\ConsultingHoursAlerts;
use App\Console\Commands\SetupConsultingHoursMobile;
use App\Mail\Admin\ConsultingHoursLow;
use App\Models\Client;
use App\Models\Company;
use App\Services\IntegrateCore\ConsultingHours;
use App\Services\IntegrateCore\ConsultingHoursAlertSender;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ConsultingHoursTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        config(['integratecore.consulting_hours_alerts_enabled' => true]);
        Mail::fake();
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->default(1);
            $table->string('name')->default('Example');
            $table->string('number')->default('C-001');
            $table->decimal('consulting_hours_balance', 20, 6)->default(0);
            $table->boolean('consulting_hours_funded')->default(false);
            $table->timestamp('consulting_hours_alerted_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            for ($slot = 1; $slot <= 4; $slot++) {
                $table->string("custom_value{$slot}")->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->text('settings')->nullable();
            $table->text('react_settings')->nullable();
            $table->timestamps();
        });
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->text('custom_fields')->nullable();
            $table->unsignedTinyInteger('consulting_hours_custom_field')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function client(float $balance = 0): Client
    {
        $client = new Client();
        $client->forceFill([
            'consulting_hours_balance' => $balance,
            'consulting_hours_funded' => false,
            'consulting_hours_alerted_at' => null,
            'is_deleted' => false,
        ]);
        return $client;
    }

    public function testNeverFundedZeroDoesNotAlertAndThresholdIncludesTwo(): void
    {
        $hours = new ConsultingHours();
        $client = $this->client();
        $hours->track($client);
        $this->assertFalse($hours->needsAlert($client));
        $client->consulting_hours_balance = 2;
        $hours->track($client);
        $this->assertTrue($hours->needsAlert($client));
        $client->consulting_hours_alerted_at = now();
        $client->consulting_hours_balance = -1;
        $hours->track($client);
        $this->assertFalse($hours->needsAlert($client));
    }

    public function testOnlyRefillAboveThresholdResetsAlert(): void
    {
        $hours = new ConsultingHours();
        $client = $this->client(1);
        $hours->track($client);
        $client->consulting_hours_alerted_at = now();
        $client->consulting_hours_balance = 2;
        $hours->track($client);
        $this->assertNotNull($client->consulting_hours_alerted_at);
        $client->consulting_hours_balance = 2.000001;
        $hours->track($client);
        $this->assertNull($client->consulting_hours_alerted_at);
        $client->consulting_hours_balance = 0;
        $hours->track($client);
        $this->assertTrue($hours->needsAlert($client));
    }

    public function testManualReductionFromPositiveToZeroCountsAsFunded(): void
    {
        $hours = new ConsultingHours();
        $client = $this->client(0);
        $hours->track($client, 5);
        $this->assertTrue($hours->needsAlert($client));
        $client->is_deleted = true;
        $this->assertFalse($hours->needsAlert($client));
    }

    public function testMobileBridgePreservesStoredFieldsAndExclusionPermissions(): void
    {
        $hours = new ConsultingHours();
        $company = new Company();
        $company->custom_fields = (object) ['client3' => ConsultingHours::MOBILE_LABEL];
        $company->consulting_hours_custom_field = 3;
        $client = $this->client(1.234567);
        $client->setRelation('company', $company);
        $this->assertSame('1.234567', $hours->mobileValue($client, 3));
        $client->consulting_hours_balance = 0;
        $this->assertSame('0', $hours->mobileValue($client, 3));
        $client->custom_value3 = 'Existing field';
        $this->assertSame('Existing field', $hours->mobileValue($client, 3));
        $client->custom_value3 = '';
        $client->setRawAttributes(['custom_value3' => '']);
        $this->assertSame('', $hours->mobileValue($client, 3));
    }

    public function testMobileEditsCannotWriteComputedSlotOrOverwriteBalance(): void
    {
        $hours = new ConsultingHours();
        $company = new Company();
        $company->custom_fields = (object) ['client4' => ConsultingHours::MOBILE_LABEL];
        $company->consulting_hours_custom_field = 4;
        $input = ['custom_value4' => '99', 'custom_value1' => 'real value', 'name' => 'new name'];
        $this->assertSame(['custom_value1' => 'real value', 'name' => 'new name'], $hours->stripComputedInput($input, $company));
        $company->custom_fields = (object) ['client4' => 'Changed label'];
        $this->assertSame($input, $hours->stripComputedInput($input, $company));
    }

    public function testProjectedClientPreservesStoredFieldsWithoutLoadingCompany(): void
    {
        $client = new Client();
        $client->setRawAttributes(['id' => 1, 'custom_value1' => 'Stored value']);
        $hours = new ConsultingHours();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame('Stored value', $hours->mobileValue($client, 1));
        $this->assertSame('', $hours->mobileValue($client, 2));
        $this->assertFalse($client->relationLoaded('company'));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function testSenderUsesConfiguredMailTransportWithoutSendingRealEmail(): void
    {
        $company = new Company();
        $company->settings = (object) ['email_sending_method' => 'default'];
        $client = $this->client(1.5);
        $client->name = 'Example';
        $client->number = 'C-001';
        $client->setRelation('company', $company);
        (new ConsultingHoursAlertSender())->send($client);
        Mail::assertSent(ConsultingHoursLow::class, fn ($mail) => $mail->hasTo('bradley@integratecore.net') && $mail->hours === 1.5);
    }

    public function testCommandDeduplicatesAndRefillAllowsAnotherAlert(): void
    {
        DB::table('clients')->insert(['id' => 1, 'consulting_hours_balance' => 2, 'consulting_hours_funded' => true]);
        DB::table('clients')->insert(['id' => 2, 'consulting_hours_balance' => 0]);
        $sender = Mockery::mock(ConsultingHoursAlertSender::class);
        $sender->shouldReceive('send')->twice()->with(Mockery::on(fn ($client) => $client->id === 1));
        $this->app->instance(ConsultingHoursAlertSender::class, $sender);
        $this->artisan('integratecore:consulting-hours-alerts')->assertSuccessful();
        $this->artisan('integratecore:consulting-hours-alerts')->assertSuccessful();
        $this->assertNotNull(DB::table('clients')->where('id', 1)->value('consulting_hours_alerted_at'));
        $client = Client::withoutEagerLoads()->find(1);
        $client->consulting_hours_balance = 5;
        (new ConsultingHours())->track($client);
        $client->saveQuietly();
        $client->consulting_hours_balance = 1;
        (new ConsultingHours())->track($client);
        $client->saveQuietly();
        $this->artisan('integratecore:consulting-hours-alerts')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function testFailedSendRemainsPendingAndCanRetry(): void
    {
        DB::table('clients')->insert(['id' => 1, 'consulting_hours_balance' => 1, 'consulting_hours_funded' => true]);
        $sender = Mockery::mock(ConsultingHoursAlertSender::class);
        $sender->shouldReceive('send')->once()->andThrow(new \RuntimeException('Test transport failure'));
        $this->app->instance(ConsultingHoursAlertSender::class, $sender);
        $this->artisan('integratecore:consulting-hours-alerts')->assertFailed();
        $this->assertNull(DB::table('clients')->value('consulting_hours_alerted_at'));
        $sender = Mockery::mock(ConsultingHoursAlertSender::class);
        $sender->shouldReceive('send')->once();
        $this->app->instance(ConsultingHoursAlertSender::class, $sender);
        $this->artisan('integratecore:consulting-hours-alerts')->assertSuccessful();
        $this->assertNotNull(DB::table('clients')->value('consulting_hours_alerted_at'));
    }

    public function testDisabledCommandNeverSendsOrMarks(): void
    {
        DB::table('clients')->insert(['id' => 1, 'consulting_hours_balance' => 1, 'consulting_hours_funded' => true]);
        config(['integratecore.consulting_hours_alerts_enabled' => false]);
        $sender = Mockery::mock(ConsultingHoursAlertSender::class);
        $sender->shouldNotReceive('send');
        $this->app->instance(ConsultingHoursAlertSender::class, $sender);
        $this->artisan('integratecore:consulting-hours-alerts')->assertSuccessful();
        $this->assertNull(DB::table('clients')->value('consulting_hours_alerted_at'));
    }

    public function testQuietTaskAndInvoiceBalanceUpdatesPersistFundingAndResetState(): void
    {
        DB::table('clients')->insert(['id' => 1, 'consulting_hours_balance' => 0]);
        $client = Client::withoutEagerLoads()->find(1);
        $client->service()->updateConsultingHoursBalance(5);
        $this->assertTrue((bool) DB::table('clients')->value('consulting_hours_funded'));
        $client->service()->updateConsultingHoursBalance(-3);
        $this->assertEquals(2, DB::table('clients')->value('consulting_hours_balance'));
        DB::table('clients')->update(['consulting_hours_alerted_at' => now()]);
        $client->service()->updateConsultingHoursBalance(0.000001);
        $this->assertNull(DB::table('clients')->value('consulting_hours_alerted_at'));
        Mail::assertNothingSent();
    }

    public function testManualSavePersistsFundedStateBeforeObserversRunAfterCommit(): void
    {
        DB::table('clients')->insert(['id' => 1, 'consulting_hours_balance' => 4]);
        $client = Client::withoutEagerLoads()->find(1);
        // Remove unrelated integrations; keep Client::booted's synchronous saving callback.
        \Illuminate\Support\Facades\Event::forget('eloquent.updated: ' . Client::class);
        DB::transaction(function () use ($client) {
            $client->consulting_hours_balance = 0;
            $client->save();
            $this->assertTrue((bool) DB::table('clients')->value('consulting_hours_funded'));
        });
    }

    public function testMigrationPreservesSavedColumnPreferencesIncludingLegacyValues(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['consulting_hours_funded', 'consulting_hours_alerted_at']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('consulting_hours_custom_field'));
        DB::table('clients')->insert([['id' => 1, 'consulting_hours_balance' => 0], ['id' => 2, 'consulting_hours_balance' => 3]]);
        DB::table('company_user')->insert([
            ['id' => 1, 'company_id' => 1, 'react_settings' => json_encode(['react_table_columns' => ['client' => ['number', 'name']], 'dark_mode' => true]), 'settings' => '{}'],
            ['id' => 2, 'company_id' => 1, 'react_settings' => '{}', 'settings' => json_encode(['react_table_columns' => ['client' => ['name', 'balance']]])],
        ]);
        $migration = require base_path('database/migrations/2026_10_04_000001_add_consulting_hours_notification_state.php');
        $migration->up();
        $react = json_decode(DB::table('company_user')->where('id', 1)->value('react_settings'), true);
        $this->assertSame(['number', 'name', 'consulting_hours_balance'], $react['react_table_columns']['client']);
        $this->assertTrue($react['dark_mode']);
        $legacy = json_decode(DB::table('company_user')->where('id', 2)->value('react_settings'), true);
        $this->assertSame(['name', 'balance', 'consulting_hours_balance'], $legacy['react_table_columns']['client']);
        $this->assertFalse((bool) DB::table('clients')->where('id', 1)->value('consulting_hours_funded'));
        $this->assertTrue((bool) DB::table('clients')->where('id', 2)->value('consulting_hours_funded'));
    }

    public function testPostCommitReconcileClearsAConcurrentAlertMarkerAfterRefill(): void
    {
        DB::table('clients')->insert(['id' => 1, 'consulting_hours_balance' => 5, 'consulting_hours_funded' => true, 'consulting_hours_alerted_at' => now()]);
        $client = Client::withoutEagerLoads()->find(1);
        (new ConsultingHours())->reconcile($client);
        $this->assertNull(DB::table('clients')->value('consulting_hours_alerted_at'));
    }

    public function testMobileSetupSkipsLabeledAndPreviouslyUsedSlotsAndIsIdempotent(): void
    {
        DB::table('companies')->insert(['id' => 1, 'custom_fields' => json_encode(['client1' => 'Existing label'])]);
        DB::table('clients')->insert(['id' => 1, 'company_id' => 1, 'custom_value2' => 'Existing value', 'deleted_at' => now()]);
        DB::table('company_user')->insert(['id' => 1, 'company_id' => 1, 'settings' => json_encode(['table_columns' => ['client' => ['name', 'number']], 'accent_color' => 'blue'])]);
        // Isolate the setup command from unrelated company webhooks and tax integrations.
        Company::flushEventListeners();
        $this->artisan('integratecore:consulting-hours-mobile', ['company_id' => 1])->assertSuccessful();
        $this->artisan('integratecore:consulting-hours-mobile', ['company_id' => 1])->assertSuccessful();
        $company = Company::find(1);
        $this->assertSame(3, (int) $company->consulting_hours_custom_field);
        $this->assertSame('Existing label', $company->custom_fields->client1);
        $this->assertSame(ConsultingHours::MOBILE_LABEL, $company->custom_fields->client3);
        $this->assertSame('Existing value', DB::table('clients')->value('custom_value2'));
        $this->assertNull(DB::table('clients')->value('custom_value3'));
        $settings = json_decode(DB::table('company_user')->value('settings'), true);
        $this->assertSame(['name', 'number', 'custom3'], $settings['table_columns']['client']);
        $this->assertSame('blue', $settings['accent_color']);
    }
}
