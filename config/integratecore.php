<?php

return [
    'enabled' => env('INTEGRATECORE_FILES_ENABLED', false),
    // This account must be scoped to IntegrateCore-Documents/02. Client Information.
    'url' => env('INTEGRATECORE_FILES_URL'),
    'username' => env('INTEGRATECORE_FILES_USERNAME'),
    'password' => env('INTEGRATECORE_FILES_PASSWORD'),
    'library_url' => env('INTEGRATECORE_LIBRARY_URL', 'https://files.integratecore.net/files/IntegrateCore-Documents/02.%20Client%20Information/'),
];
