<?php
return [
    'site_url' => 'http://localhost',
    'env' => 'development',
    'db' => ['dsn' => 'sqlite::memory:'],
    'mail' => ['from' => 'test@localhost', 'from_name' => 'Test', 'contact_to' => 'test@localhost', 'transport' => 'log'],
    'security' => ['session_name' => 't', 'session_lifetime' => 7200, 'max_login_attempts' => 5, 'require_2fa_for_admins' => false],
    'uploads' => ['max_bytes' => 25 * 1024 * 1024],
];
