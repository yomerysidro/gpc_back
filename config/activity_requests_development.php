<?php

return [
    'enabled' => (bool) env('ACTIVITY_REQUESTS_DEVELOPMENT_ENABLED', false),
    'exclusive' => (bool) env('ACTIVITY_REQUESTS_TEST_EXCLUSIVE', false),
    'seed_password' => env('ACTIVITY_REQUESTS_TEST_USER_PASSWORD'),
];
