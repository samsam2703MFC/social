<?php
/**
 * Routeur du serveur de développement PHP pour l'espace franchisé assemblé (bin/franchisee-build.sh) :
 * les adresses propres (calendrier, publier, operations, instagram), comme le .htaccess sous Apache.
 *   php -S 127.0.0.1:8098 -t DEST bin/franchisee-router.php
 */
$uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$propres = ['calendrier' => 'Calendrier.dc.html', 'publier' => 'Publier.dc.html', 'operations' => 'Campagnes.dc.html', 'instagram' => 'Instagram.dc.html'];
$nom = basename($uri);
if (isset($propres[$nom])) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($_SERVER['DOCUMENT_ROOT'] . '/' . $propres[$nom]);
    return true;
}
return false;
