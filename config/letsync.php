<?php

return [
    'token' => env('LETSYNC_TOKEN'),

    'module_id' => (int) env('LETSYNC_MODULE_ID', 1),

    'language_id' => (int) env('LETSYNC_OC_LANGUAGE_ID', 1),

    'image_base_url' => rtrim((string) env('LETSYNC_IMAGE_BASE_URL', 'https://www.green7.ae/image'), '/'),

    'image_disk' => env('LETSYNC_IMAGE_DISK', 'public'),

    'fallback_category' => env('LETSYNC_FALLBACK_CATEGORY', 'Uncategorized'),

    'queue' => env('LETSYNC_QUEUE', 'letsync'),
];
