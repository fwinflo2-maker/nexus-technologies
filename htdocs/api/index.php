<?php

declare(strict_types=1);

/**
 * Stub InfinityFree : /api/* doit aboutir au front controller même si le
 * rewrite de la racine htdocs est ignoré. Le runtime PHP vit dans api-app/.
 */
require dirname(__DIR__) . '/api-app/public/index.php';
