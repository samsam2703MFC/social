<?php
declare(strict_types=1);

/** Contrôleur frontal : /social/api/v1/social/* → src/api.php. */
require __DIR__ . '/../../src/api.php';

$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pos = strpos($uri, '/api/v1/social');
$path = $pos === false ? '/' : substr($uri, $pos + strlen('/api/v1/social'));
socHandle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path === '' ? '/' : $path);
