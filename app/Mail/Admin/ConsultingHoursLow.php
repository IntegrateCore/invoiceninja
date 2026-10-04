<?php

namespace App\Mail\Admin;

use Illuminate\Mail\Mailable;

class ConsultingHoursLow extends Mailable
{
    public function __construct(public string $clientName, public float $hours, public string $clientNumber) {}

    public function build(): self
    {
        return $this->subject("Low consulting hours: {$this->clientName}")
            ->text('email.integratecore.consulting_hours_low');
    }
}
