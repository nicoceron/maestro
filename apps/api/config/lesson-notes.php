<?php

return [
    'attachments' => [
        'disk' => env('LESSON_ATTACHMENT_DISK', 'lesson_attachments'),
        'minimum_bytes' => (int) env('LESSON_ATTACHMENT_MIN_BYTES', 1),
        'maximum_bytes' => (int) env('LESSON_ATTACHMENT_MAX_BYTES', 25 * 1024 * 1024),
        'maximum_active_per_note' => (int) env('LESSON_ATTACHMENT_MAX_ACTIVE_PER_NOTE', 20),
        'maximum_active_bytes_per_note' => (int) env('LESSON_ATTACHMENT_MAX_ACTIVE_BYTES_PER_NOTE', 100 * 1024 * 1024),
        'download_url_minutes' => (int) env('LESSON_ATTACHMENT_DOWNLOAD_URL_MINUTES', 5),
        'quarantine_retention_hours' => (int) env('LESSON_ATTACHMENT_QUARANTINE_RETENTION_HOURS', 168),
        'pending_retention_hours' => (int) env('LESSON_ATTACHMENT_PENDING_RETENTION_HOURS', 336),
        'retired_retention_hours' => (int) env('LESSON_ATTACHMENT_RETIRED_RETENTION_HOURS', 24),
        'purge_batch_size' => (int) env('LESSON_ATTACHMENT_PURGE_BATCH_SIZE', 100),
        'allowed_types' => [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'webp' => ['image/webp'],
            'mp3' => ['audio/mpeg', 'audio/mp3'],
            'm4a' => ['audio/mp4', 'audio/x-m4a'],
            'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
            'ogg' => ['audio/ogg', 'application/ogg'],
        ],
        'scanner' => [
            // Unknown or unavailable scanners deliberately fail closed.
            'driver' => env('LESSON_ATTACHMENT_SCANNER', 'fail-closed'),
            'clamav' => [
                'host' => env('CLAMAV_HOST', '127.0.0.1'),
                'port' => (int) env('CLAMAV_PORT', 3310),
                'timeout_seconds' => (float) env('CLAMAV_TIMEOUT_SECONDS', 15),
                'engine_version' => env('CLAMAV_ENGINE_VERSION'),
            ],
        ],
    ],
];
