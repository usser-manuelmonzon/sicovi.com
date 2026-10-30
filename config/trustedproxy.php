<?php

return [
    // Read by Laravel's middleware at request time, including with config:cache.
    // Leave unset locally; use * only behind Render's controlled ingress.
    'proxies' => env('TRUSTED_PROXIES'),
];
