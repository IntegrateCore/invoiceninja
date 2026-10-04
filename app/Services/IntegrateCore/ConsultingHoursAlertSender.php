<?php

namespace App\Services\IntegrateCore;

use App\Mail\Admin\ConsultingHoursLow;
use App\Models\Client;
use Illuminate\Support\Facades\Mail;

class ConsultingHoursAlertSender
{
    /** Transport failures propagate so the scheduled command can retry. */
    public function send(Client $client): void
    {
        $company = $client->company;
        $settings = $company->settings;
        $method = $settings->email_sending_method ?? 'default';
        $mailable = new ConsultingHoursLow($client->name ?: $client->number, (float) $client->consulting_hours_balance, (string) $client->number);
        $recipient = config('integratecore.consulting_hours_alert_email', 'bradley@integratecore.net');

        if ($method === 'smtp') {
            // Match Ninja's company SMTP settings without mutating the global mailer.
            $mailer = Mail::build([
                'transport' => 'smtp',
                'host' => $company->smtp_host,
                'port' => (int) $company->smtp_port,
                'username' => $company->smtp_username,
                'password' => $company->smtp_password,
                'encryption' => $company->smtp_encryption ?? 'tls',
                'local_domain' => $company->smtp_local_domain ?: null,
                'verify_peer' => $company->smtp_verify_peer ?? true,
                'timeout' => 30,
            ]);
            $from = $settings->custom_sending_email ?: $company->owner()->email;
            $mailable->from($from, $settings->email_from_name ?: $company->present()->name());
        } elseif ($method === 'default') {
            $mailer = Mail::mailer();
        } else {
            throw new \RuntimeException("Consulting hours alerts require default or company SMTP mail (configured: {$method}).");
        }
        $mailer->to($recipient)->send($mailable);
    }
}
