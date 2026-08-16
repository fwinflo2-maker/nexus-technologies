<?php

// Racine du projet (mirroir de public/index.php : BASE_PATH + APP_KEY requis).
define('BASE_PATH', dirname(__DIR__));
define('APP_KEY', 'nexus-dev-data-key-change-me');

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', getenv('DB_NAME') ?: 'nexus_test');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

define('APP_ENV', 'development');
define('JWT_SECRET', 'nexus-dev-secret-change-me');
define('JWT_TTL', 86400);


