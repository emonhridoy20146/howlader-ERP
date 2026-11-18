<?php

/*
| Field Operations integration API (read + payment intake) for the Howlader
| Field App backend. Additive module: nothing here changes existing behaviour.
| Off unless FIELD_API_ENABLED=true. See docs/field-api/README.md.
*/

return [
    'enabled' => (bool) env('FIELD_API_ENABLED', false),

    // SHA-256 hex of the bearer token(s). Two slots allow rotation without downtime.
    'token_sha256' => array_values(array_filter([
        env('FIELD_API_TOKEN_SHA256'),
        env('FIELD_API_TOKEN_NEXT_SHA256'),
    ])),

    // users.id of the dedicated integration user (must NOT be an admin; see README).
    'integration_user_id' => env('FIELD_API_USER_ID') !== null ? (int) env('FIELD_API_USER_ID') : null,

    // Oldest collection date (in days before today, Asia/Dhaka) the API accepts.
    // Empty or unset = no limit (owner decision OD-28, 2026-11-18: a collection of any past date is accepted,
    // as the office can with `backdate-sales-invoice`). A future date is always refused.
    'max_backdate_days' => is_numeric(env('FIELD_API_MAX_BACKDATE_DAYS')) ? (int) env('FIELD_API_MAX_BACKDATE_DAYS') : null,

    'page_size_default' => 100,
    'page_size_max' => 200,
    'recent_payments_per_customer' => 20,

    // Requests per minute for the integration token.
    'rate_per_minute' => (int) env('FIELD_API_RATE_PER_MINUTE', 300),
];
