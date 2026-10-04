<?php

return [
    'consulting_hours_alerts_enabled' => env('INTEGRATECORE_CONSULTING_HOURS_ALERTS_ENABLED', false),
    'consulting_hours_alert_email' => env('INTEGRATECORE_CONSULTING_HOURS_ALERT_EMAIL', 'bradley@integratecore.net'),
    'enabled' => env('INTEGRATECORE_FILES_ENABLED', false),
    // This account must be scoped to IntegrateCore-Documents/02. Client Information.
    'url' => env('INTEGRATECORE_FILES_URL'),
    'username' => env('INTEGRATECORE_FILES_USERNAME'),
    'password' => env('INTEGRATECORE_FILES_PASSWORD'),
    'library_url' => env('INTEGRATECORE_LIBRARY_URL', 'https://files.integratecore.net/files/IntegrateCore-Documents/02.%20Client%20Information/'),
];
