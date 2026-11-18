<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('DIZICASHIER_BASE_URL', 'https://api.dizicashier.com'),

    /*
    |--------------------------------------------------------------------------
    | API login (required)
    |--------------------------------------------------------------------------
    |
    | Tokens are obtained via POST /api/account/login and stored in a private
    | JSON file (see token_storage_path). Do not use static bearer tokens.
    |
    */
    'username' => env('DIZICASHIER_USERNAME'),

    'password' => env('DIZICASHIER_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Cached access token file
    |--------------------------------------------------------------------------
    |
    | Stored under storage/app/private (gitignored). Deleted on 401; next API
    | call performs a fresh login.
    |
    */
    'token_storage_path' => env(
        'DIZICASHIER_TOKEN_STORAGE_PATH',
        storage_path('app/private/dizicashier-token.json'),
    ),

    /*
    |--------------------------------------------------------------------------
    | Postman collection path (optional)
    |--------------------------------------------------------------------------
    |
    | Used only when overriding via CLI --collection= for other tooling.
    |
    */
    'postman_collection_path' => env('DIZICASHIER_POSTMAN_COLLECTION', base_path('Dizicash.postman_collection.json')),

    /*
    |--------------------------------------------------------------------------
    | Import actor
    |--------------------------------------------------------------------------
    |
    | Laravel user id used for created_by / posted_by / recorded_by on
    | imported records. If unset or invalid, the sync uses the first active user.
    |
    */
    'import_user_id' => env('DIZICASHIER_IMPORT_USER_ID'),

    'items_per_page' => (int) env('DIZICASHIER_ITEMS_PER_PAGE', 50),

    /*
    |--------------------------------------------------------------------------
    | GET /api/stock/ query (Products → stocks in Dizicash.postman_collection.json)
    |--------------------------------------------------------------------------
    |
    | Merged into each paginated request (plus page, itemsPerPage). Stock sync only
    | updates rows whose ItemId matches products.dizicashier_uuid — run products sync first
    | if adjusted count stays 0. Omit ListFilter.Quantity to include zero-qty API rows;
    | add 'ListFilter.Quantity' => '>0' to mirror the Postman example and reduce payload.
    |
    */
    'stock_api_query' => [
        'sort' => 'Name-asc',
        'ListFilter.ItemCategoryIds' => '',
    ],

];
