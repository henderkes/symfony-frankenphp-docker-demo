<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load .env so the kernel has DATABASE_URL, APP_ENV, etc.
(new Symfony\Component\Dotenv\Dotenv('APP_ENV', 'APP_DEBUG'))
    ->bootEnv(__DIR__ . '/.env');
