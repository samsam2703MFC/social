<?php
/**
 * Routeur du serveur de développement PHP :
 *   php -S 0.0.0.0:8090 -t public public/router.php   → http://localhost:8090/
 */
$uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_contains($uri, '/api/v1/social')) {
    require __DIR__ . '/api/index.php';
    return true;
}
$f = __DIR__ . rawurldecode($uri);
if ($uri !== '/' && is_file($f)) { return false; }
if (str_starts_with($uri, '/uploads/')) { http_response_code(404); echo 'Fichier absent'; return true; }
require __DIR__ . '/index.html';
return true;
