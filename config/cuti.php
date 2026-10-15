<?php

return [
    'demo' => (bool) env('DEMO_MODE', false),
    'mail_mode' => env('CUTI_MAIL_MODE', 'capture'),
    'allowed_email_domains' => array_values(array_filter(array_map('trim', explode(',', strtolower(env('ALLOWED_EMAIL_DOMAINS', '')))))),
    'bootstrap_email' => env('BOOTSTRAP_EMAIL'),
    'bootstrap_password' => env('BOOTSTRAP_PASSWORD'),
    'branch_name' => env('BRANCH_NAME', 'Kantor Cabang'),
    'cookie' => 'cuti_laravel_session',
];
