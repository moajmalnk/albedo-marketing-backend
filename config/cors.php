<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => (static function (): array {
        $raw = trim((string) env('CORS_ALLOWED_ORIGINS', ''));
        if ($raw !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        return [
            'https://marketing.albedoedu.com',
            'http://localhost:8080',
            'http://127.0.0.1:8080',
        ];
    })(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
