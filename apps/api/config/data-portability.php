<?php

return [
    'disk' => env('CRM_DATA_PORTABILITY_DISK', 'crm_data_portability'),
    'max_upload_bytes' => 10 * 1024 * 1024,
    'max_rows' => 25_000,
    'artifact_ttl_hours' => 48,
    'resolution_ttl_minutes' => 30,
    'step_up_seconds' => 600,
    'row_lease_seconds' => 120,
];
