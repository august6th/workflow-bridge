<?php

return [
    'base_url' => env('WORKFLOW_BASE_URL', 'http://workflow.aug.test/api'),
    'sso_secret' => env('WORKFLOW_SSO_SECRET', ''),
    'callback_secret' => env('WORKFLOW_CALLBACK_SECRET', ''),
    'owner_system' => env('WORKFLOW_OWNER_SYSTEM', 'erp'),

    'sso_clock_skew_seconds' => 300,
    'callback_clock_skew_seconds' => 300,
    'start_lease_seconds' => 300,
    'start_retry_base_seconds' => 60,
    'start_retry_max_seconds' => 3600,
    'apply_lease_seconds' => 300,
    'apply_retry_base_seconds' => 60,
    'apply_retry_max_seconds' => 3600,
    'http_timeout' => 15,
    'token_cache_seconds' => 3600,
];
