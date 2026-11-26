<?php

namespace Tests\Unit\IntegrateCore;

use App\Http\Controllers\Support\Messages\SendingController;
use App\Mail\SupportMessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VendorSupportRoutingTest extends TestCase
{
    public function test_vendor_support_form_uses_the_official_recipient_not_the_company_sender(): void
    {
        Mail::fake();
        config(['mail.from.address' => 'company-support@example.com']);

        $response = (new SendingController())(Request::create(
            '/api/v1/support/messages/send',
            'POST',
            ['message' => 'Synthetic support message', 'send_logs' => false]
        ));

        $this->assertSame(200, $response->getStatusCode());
        Mail::assertSent(SupportMessageSent::class, function (SupportMessageSent $mail) {
            return $mail->hasTo('contact@invoiceninja.com')
                && !$mail->hasTo('company-support@example.com');
        });
    }
}
