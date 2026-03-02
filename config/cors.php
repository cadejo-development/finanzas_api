<?php

return [
    /*
     * You can allow requests from any origin by providing a wildcard (*).
     * You can also specify specific domains.
     */
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
