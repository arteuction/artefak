<?php

declare(strict_types=1);

return [
    /*
     * OpenAPI info block — reflected into the generated spec.
     */
    'info' => [
        'title'   => 'ARTeuCtion API',
        'version' => env('APP_VERSION', '1.0.0'),
    ],

    /*
     * Only document the v1 API prefix.
     * Webhook, Filament, and debug routes are excluded.
     */
    'api_path' => 'api/v1',

    'api_domain' => null,

    /*
     * Paths to exclude from the generated spec.
     * Webhook endpoints accept raw Stripe payloads, not JSON contracts.
     */
    'exclude_paths' => [
        'api/v1/stripe/webhook',
    ],

    /*
     * Restrict the /docs and /docs/api.json endpoints to admin users in production.
     */
    'middleware' => [
        'web',
    ],

    'extensions' => [],
];
