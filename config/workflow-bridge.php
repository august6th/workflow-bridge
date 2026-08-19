<?php

return [
    'base_url' => env('WORKFLOW_BASE_URL', 'http://workflow.aug.test/api'),
    'sso_secret' => env('WORKFLOW_SSO_SECRET', ''),
    'callback_secret' => env('WORKFLOW_CALLBACK_SECRET', ''),
    'owner_system' => env('WORKFLOW_OWNER_SYSTEM', 'erp'),

    'sso_clock_skew_seconds' => max(30, (int) env('WORKFLOW_SSO_CLOCK_SKEW_SECONDS', 300)),
    'callback_clock_skew_seconds' => max(30, (int) env('WORKFLOW_CALLBACK_CLOCK_SKEW_SECONDS', 300)),
    'start_lease_seconds' => max(30, (int) env('WORKFLOW_START_LEASE_SECONDS', 300)),
    'start_retry_base_seconds' => max(10, (int) env('WORKFLOW_START_RETRY_BASE_SECONDS', 60)),
    'start_retry_max_seconds' => max(60, (int) env('WORKFLOW_START_RETRY_MAX_SECONDS', 3600)),
    'apply_lease_seconds' => max(30, (int) env('WORKFLOW_APPLY_LEASE_SECONDS', 300)),
    'apply_retry_base_seconds' => max(10, (int) env('WORKFLOW_APPLY_RETRY_BASE_SECONDS', 60)),
    'apply_retry_max_seconds' => max(60, (int) env('WORKFLOW_APPLY_RETRY_MAX_SECONDS', 3600)),
    'http_timeout' => max(1, (int) env('WORKFLOW_HTTP_TIMEOUT', 15)),
    'token_cache_seconds' => max(60, (int) env('WORKFLOW_TOKEN_CACHE_SECONDS', 3600)),
];
