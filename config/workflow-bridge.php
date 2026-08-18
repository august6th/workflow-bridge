<?php

return [
    'base_url' => env('WORKFLOW_BASE_URL', 'http://workflow.aug.test/api'),
    'sso_secret' => env('WORKFLOW_SSO_SECRET', ''),
    'sso_clock_skew_seconds' => max(30, (int) env('WORKFLOW_SSO_CLOCK_SKEW_SECONDS', 300)),
    'callback_secret' => env('WORKFLOW_CALLBACK_SECRET', ''),
    'callback_clock_skew_seconds' => max(30, (int) env('WORKFLOW_CALLBACK_CLOCK_SKEW_SECONDS', 300)),
    'start_lease_seconds' => max(30, (int) env('WORKFLOW_START_LEASE_SECONDS', 300)),
    'start_retry_base_seconds' => max(10, (int) env('WORKFLOW_START_RETRY_BASE_SECONDS', 60)),
    'start_retry_max_seconds' => max(60, (int) env('WORKFLOW_START_RETRY_MAX_SECONDS', 3600)),
    'apply_lease_seconds' => max(30, (int) env('WORKFLOW_APPLY_LEASE_SECONDS', 300)),
    'apply_retry_base_seconds' => max(10, (int) env('WORKFLOW_APPLY_RETRY_BASE_SECONDS', 60)),
    'apply_retry_max_seconds' => max(60, (int) env('WORKFLOW_APPLY_RETRY_MAX_SECONDS', 3600)),

    'client' => [
        'user_name' => env('WORKFLOW_CLIENT_USER_NAME', 'ic_workflow_client'),
        'name' => env('WORKFLOW_CLIENT_NAME', 'IC Workflow Client'),
        'source_system' => env('WORKFLOW_CLIENT_SOURCE_SYSTEM', 'ic'),
        'permissions' => [
            'workflow:external:start',
            'workflow:external:view',
        ],
    ],

    'owner_system' => env('WORKFLOW_OWNER_SYSTEM', 'ic'),

    'processes' => [
        'skc_approval' => env('WORKFLOW_SKC_APPROVAL_PROCESS_CODE', 'skc_approval'),
    ],

    'http_timeout' => (int) env('WORKFLOW_HTTP_TIMEOUT', 15),
    'token_cache_seconds' => max(60, (int) env('WORKFLOW_TOKEN_CACHE_SECONDS', 3600)),
];
