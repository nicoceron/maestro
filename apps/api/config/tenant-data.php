<?php

return [
    'disk' => env('TENANT_EXPORT_DISK', 'tenant_exports'),
    'export_ttl_hours' => (int) env('TENANT_EXPORT_TTL_HOURS', 24),
    'download_ttl_minutes' => (int) env('TENANT_EXPORT_DOWNLOAD_TTL_MINUTES', 5),
    'cooling_off_days' => (int) env('TENANT_DELETION_COOLING_OFF_DAYS', 14),
    'quarantine_days' => (int) env('TENANT_DELETION_QUARANTINE_DAYS', 30),
    'default_retention_days' => (int) env('TENANT_DATA_RETENTION_DAYS', 2555),
];
