<?php

/*
|--------------------------------------------------------------------------
| SMS module (BulkSMSBD)
|--------------------------------------------------------------------------
|
| SMS is never sent automatically. Every message leaves the system only when
| an authorised user presses "Send" on the SMS page.
|
*/

return [

    /*
     * Role slugs allowed to open the SMS page and send messages.
     * Users holding any one of these roles are allowed; nobody else is.
     */
    'allowed_roles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SMS_ALLOWED_ROLES', 'admin,super-admin,super_admin,system'))
    ))),

    /*
     * When false the page still works (preview, history) but the provider is
     * never called; messages are logged with status "failed / disabled".
     */
    'enabled' => (bool) env('SMS_ENABLED', true),

    'default_sender_type' => env('SMS_DEFAULT_SENDER_TYPE', 'non_masking'), // masking | non_masking

    /* Country code prepended to local 01XXXXXXXXX numbers. */
    'country_code' => '880',

    /* Hard cap on recipients in one bulk send. */
    'max_bulk_recipients' => (int) env('SMS_MAX_BULK_RECIPIENTS', 5000),

    /* Recipients per provider request when sending a bulk batch. */
    'chunk_size' => (int) env('SMS_CHUNK_SIZE', 100),

    /* Queue used for bulk batches (the project already runs a queue worker). */
    'queue' => env('SMS_QUEUE', 'default'),

    'bulksmsbd' => [
        'base_url' => rtrim((string) env('BULKSMSBD_BASE_URL', 'http://bulksmsbd.net/api'), '/'),
        'api_key' => env('BULKSMSBD_API_KEY'),

        /* Approved brand name (masking) and the numeric sender (non-masking). */
        'sender_id_masking' => env('BULKSMSBD_SENDER_ID_MASKING'),
        'sender_id_non_masking' => env('BULKSMSBD_SENDER_ID_NON_MASKING'),

        /* "text" works for English and Bangla on BulkSMSBD; change only if the provider asks. */
        'type' => env('BULKSMSBD_TYPE', 'text'),

        'timeout' => (int) env('BULKSMSBD_TIMEOUT', 30),

        /* Your per-SMS rate in BDT, used to estimate how many SMS the balance still covers. */
        'rate_masking' => (float) env('BULKSMSBD_RATE_MASKING', 0),
        'rate_non_masking' => (float) env('BULKSMSBD_RATE_NON_MASKING', 0),

        /* Seconds the provider balance is cached on the page. */
        'balance_cache_seconds' => 60,
    ],

];
