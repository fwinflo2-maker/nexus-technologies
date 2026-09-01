<?php

declare(strict_types=1);

/**
 * Manifest PWA avec MIME forcé.
 * InfinityFree intercepte .json / .webmanifest (page HTML anti-bot) :
 * Chrome affiche « Manifest: Line: 1, column: 1, Syntax error ».
 * L'URL publique est /manifest.txt (extension statique, comme robots.txt).
 */
header('Content-Type: application/manifest+json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');

$file = __DIR__ . '/manifest.json';
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
readfile($file);
