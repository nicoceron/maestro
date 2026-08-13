<?php

return [
    'tenant_exports' => [
        'driver' => 'local',
        'root' => storage_path('app/private/tenant-exports'),
        'visibility' => 'private',
        'throw' => true,
        'report' => true,
    ],
];
