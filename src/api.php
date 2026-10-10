<?php
declare(strict_types=1);

/**
 * L'API du module : {base}/api/v1/social/{domaine}/{ressource}.
 *
 * Toutes les routes passent par la session du cockpit (src/bootstrap.php,
 * authOk) sauf /status (santé, sans secret) et /webhook (signé par Meta).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/tfb.php';
require_once __DIR__ . '/shops.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish.php';
require_once __DIR__ . '/ads.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/results.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/portefeuille.php';
require_once __DIR__ . '/instagram.php';

date_default_timezone_set('Europe/Brussels');

/** Rend [statut HTTP, corps] pour une requête. Le corps est un tableau (JSON) ou une chaîne (texte brut). */
function socRoute(string $method, string $path): array
{
    $path = '/' . trim($path, '/');

    if ($method === 'GET' && $path === '/status') {
        $db = false; $err = null;
        try { Db::pdo()->query('SELECT 1'); $db = true; } catch (Throwable $e) { $err = 'base injoignable'; }
        return [200, ['ok' => $db, 'module' => 'social', 'version' => SOCIAL_VERSION, 'db' => $db, 'dbError' => $err,
            'bo' => ['config' => isset(boConfig()['_source']), 'auth' => authEnabled()], 'meta' => $db ? Meta::statut() : null]];
    }
    if ($path === '/webhook') {
        socInstall();
        if ($method === 'GET') { return [200, socWebhookVerif()]; }
        return [200, socWebhook((string) file_get_contents('php://input'), $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null)];
    }

    socInstall();
    if ($method === 'GET' && $path === '/auth') {
        return [200, ['enabled' => authEnabled(), 'authed' => authOk(), 'login' => (string) (socSetting('url_bo') ?? '/consulant_bo/')]];
    }
    if (!authOk()) {
        return [401, ['error' => 'auth', 'message' => 'Connectez-vous au cockpit pour accéder au module Social & Ads.',
            'login' => (string) (socSetting('url_bo') ?? '/consulant_bo/')]];
    }

    if ($method === 'GET' && $path === '/state') { return [200, socStateGet()]; }
    if ($method === 'POST' && $path === '/state') { return [200, socStateSave(body())]; }
    if ($method === 'GET' && $path === '/shops') { return [200, ['shops' => socShops()]]; }
    if ($method === 'POST' && preg_match('#^/posts/([\w.\-]{1,64})/relancer$#', $path, $m)) {
        $p = socRetry($m[1]);
        if ($p === null) { throw new HttpError(404, 'post introuvable'); }
        return [200, ['post' => $p]];
    }
    if ($method === 'POST' && $path === '/parametres/cles') {
        // Les clés Meta, écrites en base (social_parametre). Un champ vide n'efface rien.
        $map = ['systemToken' => 'meta_system_token', 'appSecret' => 'meta_app_secret', 'verifyToken' => 'meta_verify_token'];
        if (preg_match('/^\d{5,20}$/', trim((string) (body()['appId'] ?? '')))) { socSettingPut('meta_app_id', trim((string) body()['appId']), false); }
        $faits = [];
        foreach ($map as $k => $cle) {
            $v = trim((string) (body()[$k] ?? ''));
            if ($v !== '') { socSettingPut($cle, $v, true); $faits[] = $cle; }
        }
        Meta::reset();
        Meta::verifierJeton();
        if ($faits) { socLog(socUser(), 'Connexion', 'Clés Meta mises à jour : ' . implode(', ', $faits)); }
        return [200, ['ok' => true, 'maj' => $faits, 'metaStatus' => Meta::statut()]];
    }
    if ($method === 'POST' && $path === '/parametres') {
        // Les réglages non secrets modifiables depuis l'écran.
        $permis = ['url_publique' => 'string', 'url_bo' => 'string', 'utilisateur' => 'string', 'marge' => 'float', 'catalogue' => 'array'];
        $faits = [];
        foreach (body() as $k => $v) {
            if (!isset($permis[$k])) { continue; }
            $v = match ($permis[$k]) { 'float' => (float) $v, 'array' => (array) $v, default => trim((string) $v) };
            socSettingPut((string) $k, $v, false); $faits[] = $k;
        }
        if ($faits) { socLog(socUser(), 'Paramètres', 'Modifiés : ' . implode(', ', $faits)); }
        return [200, ['ok' => true, 'params' => socParams()]];
    }
    if ($method === 'GET' && $path === '/meta/portefeuille') { return [200, ['portefeuille' => socSetting('meta_portefeuille'), 'businessId' => (string) (socSetting('meta_business_id') ?? '')]]; }
    if ($method === 'POST' && $path === '/meta/portefeuille/rafraichir') {
        if (isset(body()['businessId'])) { socSettingPut('meta_business_id', preg_replace('/\D/', '', (string) body()['businessId']), false); }
        return [200, ['portefeuille' => socPortefeuilleRafraichir()]];
    }
    if ($method === 'POST' && $path === '/meta/portefeuille/appliquer') {
        $r = socPortefeuilleAppliquer(!empty(body()['ecraser']));
        return [200, $r + ['shops' => socShops(), 'brand' => socBrand()]];
    }
    if ($method === 'GET' && $path === '/instagram/grille') {
        $sid = (string) ($_GET['shop'] ?? '');
        if (socShop($sid) === null) { throw new HttpError(404, 'boutique inconnue'); }
        return [200, socIgGrille($sid)];
    }
    if ($method === 'POST' && $path === '/instagram/grille') {
        // Relecture immédiate chez Instagram (le cron le fait toutes les heures).
        $sid = (string) (body()['shop'] ?? '');
        if (socShop($sid) === null) { throw new HttpError(404, 'boutique inconnue'); }
        $r = socIgMediaSync($sid);
        return [200, socIgGrille($sid) + ['synchro' => $r[$sid] ?? ($r['erreur'] ?? 'aucun compte Instagram rattaché')]];
    }
    if ($method === 'GET' && $path === '/audit/api') { return [200, socAuditGet()]; }
    if ($method === 'POST' && $path === '/audit/api') {
        // Trois minutes environ : trop long pour une requête web (le serveur la
        // coupe). L'audit part en arrière-plan — la même commande que le cron,
        // avec son verrou — et l'écran relit GET /audit/api jusqu'au résultat.
        return [200, socAuditLancer()];
    }
    if ($method === 'GET' && $path === '/shops/api') { return [200, ['shops' => socApiShops(isset($_GET['forcer'])), 'tfb' => Tfb::statut()]]; }
    if ($method === 'POST' && $path === '/parametres/tfb') {
        // Le compte de l'API TFBuddy, en base. Un mot de passe vide ne change rien.
        $b = body(); $faits = [];
        // L'adresse de l'API reçoit les mots de passe à la connexion : seuls les hôtes tfbuddy.com sont acceptés.
        if (isset($b['base']) && preg_match('#^https://([\w\-]+\.)*tfbuddy\.com(/[\w.\-/]*)?$#', trim((string) $b['base']))) { socSettingPut('tfb_base', rtrim(trim((string) $b['base']), '/'), false); $faits[] = 'base'; }
        foreach (['admin', 'consultant'] as $r) {
            if (isset($b[$r . 'Phone'])) { socSettingPut('tfb_' . $r . '_phone', trim((string) $b[$r . 'Phone']), false); $faits[] = $r . ' (téléphone)'; }
            if (trim((string) ($b[$r . 'Password'] ?? '')) !== '') { socSettingPut('tfb_' . $r . '_password', (string) $b[$r . 'Password'], true); $faits[] = $r . ' (mot de passe)'; }
            Db::exec('DELETE FROM social_parametre WHERE cle = ?', ['tfb_jeton_' . $r]);
        }
        Tfb::reset();
        $test = [];
        foreach (['admin', 'consultant'] as $r) {
            try { Tfb::jeton($r, true); $test[$r] = 'ok'; } catch (Throwable $e) { $test[$r] = $e->getMessage(); }
        }
        if ($faits) { socLog(socUser(), 'Connexion', 'API TFBuddy : ' . implode(', ', $faits)); }
        return [200, ['ok' => true, 'test' => $test, 'tfb' => Tfb::statut()]];
    }
    if ($method === 'POST' && $path === '/connexions/test') {
        $sid = isset(body()['shop']) ? (string) body()['shop'] : null;
        $r = socTestConnexions($sid);
        $ko = array_filter($r, static fn ($x) => $x['fb'] !== 'connected' || $x['igs'] !== 'connected' || $x['ads'] !== 'connected');
        socLog(socUser(), 'Connexion', 'Test des connexions Meta' . ($sid ? ' (' . ($r[0]['name'] ?? $sid) . ')' : '') . ' : ' . (count($r) - count($ko)) . ' boutique(s) sur ' . count($r) . ' entièrement connectée(s)');
        return [200, ['resultats' => $r, 'shops' => socShops()]];
    }
    if ($method === 'POST' && $path === '/sync') {
        $quoi = (string) (body()['what'] ?? 'all');
        $r = [];
        if (in_array($quoi, ['all', 'queue'], true)) { $r['queue'] = count(socRunQueue()); }
        if (in_array($quoi, ['all', 'ads'], true)) { $r['ads'] = socAdsSync(); }
        if (in_array($quoi, ['all', 'leads'], true)) { $r['leads'] = socLeadsSync(); }
        if (in_array($quoi, ['all', 'results'], true)) { $r['results'] = socResultsSync(isset(body()['campaign']) ? (string) body()['campaign'] : null); }
        return [200, ['ok' => true] + $r];
    }
    throw new HttpError(404, 'route inconnue : ' . $method . ' ' . $path);
}

/** Exécute une requête HTTP réelle et écrit la réponse. */
function socHandle(string $method, string $path): void
{
    try {
        [$code, $body] = socRoute($method, $path);
    } catch (HttpError $e) {
        [$code, $body] = [$e->status, ['error' => $e->getMessage()]];
    } catch (PDOException $e) {
        error_log('[social] ' . $e->getMessage());
        [$code, $body] = [503, ['error' => 'base de données indisponible — vérifiez le config du BO (' . boDir() . '/config/config.php)']];
    } catch (Throwable $e) {
        error_log('[social] ' . $e);
        [$code, $body] = [500, ['error' => 'erreur interne : ' . $e->getMessage()]];
    }
    http_response_code($code);
    header('Cache-Control: no-store');
    if (is_string($body)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
