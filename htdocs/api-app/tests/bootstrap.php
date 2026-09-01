<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit — charge Composer, constantes et autoload Nexus.
 */

define('BASE_PATH', dirname(__DIR__));
define('REPO_ROOT', dirname(BASE_PATH, 2));

require BASE_PATH . '/vendor/autoload.php';

define('APP_ENV', 'development');
define('APP_KEY', getenv('APP_KEY') ?: 'nexus-dev-data-key-change-me');
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'nexus-dev-test-secret-change-me');
define('JWT_TTL', 86400);

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'nexus_test');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

require BASE_PATH . '/config/constants.php';
