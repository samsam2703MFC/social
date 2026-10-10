<?php
declare(strict_types=1);

/**
 * Audit de l'API TFBuddy : quelles routes FONCTIONNENT, lesquelles non, sur
 * le serveur réel (atelierby.tfbuddy.com par défaut, `tfb_base`).
 *
 * Le swagger (/api/swagger/openapi.json) donne la liste des routes ; chaque
 * route GET est appelée avec le compte de son réalm (consultant pour
 * /consultant/*, sans compte pour /public/*, admin sinon). Les paramètres de
 * chemin d'une boutique reçoivent l'identifiant de la première boutique de
 * l'API, une date reçoit aujourd'hui ; les autres paramètres ne se devinent
 * pas — la route est notée « non testée » plutôt que déclarée en panne.
 *
 * Lecture seule : seules les routes GET sont appelées. Résultat dans
 * `social_api_audit`, résumé dans social_parametre (`tfb_audit`), affichés
 * dans Paramètres → API TFBuddy.
 */

/** Les routes de l'API que le module utilise — signalées dans l'audit. */
const SOC_TFB_UTILISEES = [
    '/api/v1/public/shops' => 'Boutiques : adresse, horaires, contact',
    '/api/v1/shops' => 'Boutiques : liste complète (compte admin)',
    '/api/v1/admin/auth/login' => 'Connexion admin',
    '/api/v1/consultant/auth/login' => 'Connexion consultant',
];

function socAuditTable(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS social_api_audit (methode VARCHAR(8) NOT NULL, chemin VARCHAR(255) NOT NULL,'
        . 'tag VARCHAR(120) NULL, realm VARCHAR(12) NULL, url_testee VARCHAR(400) NULL, http INT NULL, statut VARCHAR(12) NOT NULL,'
        . 'ms INT NULL, taille INT NULL, apercu VARCHAR(500) NULL, resume VARCHAR(255) NULL, utilise VARCHAR(160) NULL, teste_le DATETIME NOT NULL,'
        . 'PRIMARY KEY (methode, chemin), KEY idx_statut (statut)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Le statut d'un code HTTP, en mots de l'écran. */
function socAuditStatut(int $http): string
{
    return match (true) {
        $http >= 200 && $http < 300 => 'ok',
        $http === 401 || $http === 403 => 'refuse',
        $http === 404 || $http === 405 => 'absent',
        $http === 422 || $http === 400 => 'parametre',
        $http === 0 => 'injoignable',
        default => 'erreur',
    };
}

/** Un aperçu de la réponse : nombre d'éléments et clés, jamais les valeurs. */
function socAuditApercu(string $corps): string
{
    $j = json_decode($corps, true);
    if (!is_array($j)) { return $corps === '' ? 'vide' : 'non JSON'; }
    $liste = array_is_list($j) ? $j : (is_array($j['data'] ?? null) && array_is_list($j['data']) ? $j['data'] : null);
    if ($liste !== null) {
        $cles = isset($liste[0]) && is_array($liste[0]) ? array_slice(array_keys($liste[0]), 0, 14) : [];
        return count($liste) . ' élément(s)' . ($cles ? ' · ' . implode(', ', $cles) : '');
    }
    return 'objet · ' . implode(', ', array_slice(array_keys($j), 0, 16));
}

/**
 * Lance l'audit. `$max` borne le nombre de routes (0 = toutes).
 * @return array{le:string,total:int,ok:int,refuse:int,absent:int,erreur:int,parametre:int,non_teste:int,injoignable:int,duree:int}
 */
function socAuditRun(int $max = 0, int $parallele = 8): array
{
    @set_time_limit(900);
    socAuditTable();
    $t0 = microtime(true);
    [$code, $corps] = Tfb::raw('GET', Tfb::swaggerUrl(), [], null, 60);
    $spec = $code === 200 ? json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $corps), true) : null;
    if (!is_array($spec) || !is_array($spec['paths'] ?? null)) {
        throw new TfbError('swagger illisible (' . Tfb::swaggerUrl() . ' → HTTP ' . $code . ')', $code);
    }
    $hote = preg_replace('#/api/v\d+$#', '', Tfb::base());
    $shopId = (string) (socApiShops()[0]['id'] ?? '');
    $jour = date('Y-m-d');
    $jetons = [];
    foreach (['admin', 'consultant'] as $r) {
        try { $jetons[$r] = Tfb::jeton($r); } catch (Throwable $e) { $jetons[$r] = null; }
    }

    $routes = [];
    foreach ($spec['paths'] as $chemin => $ops) {
        if (!is_array($ops) || !isset($ops['get'])) { continue; }
        $op = $ops['get'];
        $realm = str_contains($chemin, '/public/') ? 'public' : (str_contains($chemin, '/consultant/') ? 'consultant' : 'admin');
        $url = $chemin; $manque = null;
        if (preg_match_all('/\{([^}]+)\}/', $chemin, $m)) {
            foreach ($m[1] as $i => $nom) {
                $avant = substr($chemin, 0, (int) strpos($chemin, '{' . $nom . '}'));
                $seg = basename(rtrim($avant, '/'));
                if (preg_match('/date|day|jour/i', $nom)) { $val = $jour; }
                elseif (preg_match('/shop/i', $nom) || preg_match('/^(shops|franchisee-shop|franchise|franchises)$/', $seg)) { $val = $shopId; }
                else { $val = null; }
                if ($val === null || $val === '') { $manque = $nom; break; }
                $url = str_replace('{' . $nom . '}', rawurlencode($val), $url);
            }
        }
        $routes[] = ['chemin' => $chemin, 'tag' => (string) (($op['tags'][0] ?? '') ?: ''), 'realm' => $realm, 'url' => $hote . $url, 'manque' => $manque];
    }
    if ($max > 0) { $routes = array_slice($routes, 0, $max); }

    $now = date('Y-m-d H:i:s');
    $ecrire = static function (array $r, ?int $http, string $statut, ?int $ms, ?int $taille, ?string $apercu, ?string $resume) use ($now): void {
        Db::exec('INSERT INTO social_api_audit (methode, chemin, tag, realm, url_testee, http, statut, ms, taille, apercu, resume, utilise, teste_le)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE tag = VALUES(tag), realm = VALUES(realm), url_testee = VALUES(url_testee), http = VALUES(http),
                    statut = VALUES(statut), ms = VALUES(ms), taille = VALUES(taille), apercu = VALUES(apercu), resume = VALUES(resume),
                    utilise = VALUES(utilise), teste_le = VALUES(teste_le)',
            ['GET', mb_substr($r['chemin'], 0, 255), mb_substr($r['tag'], 0, 120), $r['realm'], mb_substr($r['url'], 0, 400), $http, $statut, $ms, $taille,
             $apercu !== null ? mb_substr($apercu, 0, 500) : null, $resume !== null ? mb_substr($resume, 0, 255) : null, SOC_TFB_UTILISEES[$r['chemin']] ?? null, $now]);
    };

    $aTester = [];
    foreach ($routes as $r) {
        if ($r['manque'] !== null) { $ecrire($r, null, 'non_teste', null, null, null, 'paramètre {' . $r['manque'] . '} non devinable'); continue; }
        if ($r['realm'] !== 'public' && empty($jetons[$r['realm']])) { $ecrire($r, null, 'non_teste', null, null, null, 'compte ' . $r['realm'] . ' non configuré ou refusé'); continue; }
        $aTester[] = $r;
    }

    // Appels en parallèle (curl_multi), par paquets.
    foreach (array_chunk($aTester, max(1, $parallele)) as $paquet) {
        if (Tfb::$transport) {
            foreach ($paquet as $r) {
                $d = microtime(true);
                [$http, $b] = (Tfb::$transport)('GET', $r['url'], $r['realm'] === 'public' ? [] : ['Authorization: Bearer ' . $jetons[$r['realm']]], null);
                $ecrire($r, $http, socAuditStatut($http), (int) ((microtime(true) - $d) * 1000), strlen($b), $http >= 200 && $http < 300 ? socAuditApercu($b) : null, $http >= 300 ? socAuditErreur($b) : null);
            }
            continue;
        }
        $mh = curl_multi_init(); $hs = [];
        foreach ($paquet as $k => $r) {
            $ch = curl_init($r['url']);
            $h = ['Accept: application/json'];
            if ($r['realm'] !== 'public') { $h[] = 'Authorization: Bearer ' . $jetons[$r['realm']]; }
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $h]);
            curl_multi_add_handle($mh, $ch);
            $hs[$k] = $ch;
        }
        do { $st = curl_multi_exec($mh, $actifs); if ($actifs) { curl_multi_select($mh, 1.0); } } while ($actifs && $st === CURLM_OK);
        foreach ($hs as $k => $ch) {
            $r = $paquet[$k];
            $b = (string) curl_multi_getcontent($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ms = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $ecrire($r, $http, socAuditStatut($http), $ms, strlen($b), $http >= 200 && $http < 300 ? socAuditApercu($b) : null, $http >= 300 || $http === 0 ? socAuditErreur($b) : null);
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }
        curl_multi_close($mh);
    }
    // Les routes disparues du swagger ne restent pas affichées.
    Db::exec('DELETE FROM social_api_audit WHERE teste_le < ?', [$now]);

    $res = ['le' => $now, 'total' => 0, 'duree' => (int) round(microtime(true) - $t0), 'base' => Tfb::base(),
        'comptes' => ['admin' => $jetons['admin'] !== null, 'consultant' => $jetons['consultant'] !== null]];
    foreach (['ok', 'refuse', 'absent', 'erreur', 'parametre', 'non_teste', 'injoignable'] as $s) { $res[$s] = 0; }
    foreach (Db::rows('SELECT statut, COUNT(*) n FROM social_api_audit GROUP BY statut') as $r) { $res[$r['statut']] = (int) $r['n']; $res['total'] += (int) $r['n']; }
    socSettingPut('tfb_audit', $res);
    socLog('Système', 'Audit API', 'API TFBuddy : ' . $res['ok'] . ' route(s) GET fonctionnent sur ' . $res['total'] . ' (' . $res['refuse'] . ' refusées, '
        . ($res['erreur'] + $res['injoignable']) . ' en erreur, ' . $res['non_teste'] . ' non testées)');
    return $res;
}

function socAuditErreur(string $corps): string
{
    $j = json_decode($corps, true);
    $m = is_array($j) ? ($j['message'] ?? $j['error'] ?? $j['detail'] ?? null) : null;
    if (is_array($m)) { $m = json_encode($m, JSON_UNESCAPED_UNICODE); }
    return mb_substr(is_string($m) && $m !== '' ? $m : trim(strip_tags($corps)), 0, 200);
}

/** Les résultats pour l'écran. */
function socAuditGet(): array
{
    socAuditTable();
    return ['resume' => socSetting('tfb_audit'), 'tfb' => Tfb::statut(),
        'routes' => Db::rows('SELECT methode, chemin, tag, realm, http, statut, ms, taille, apercu, resume, utilise, teste_le FROM social_api_audit
                              ORDER BY FIELD(statut, "ok", "parametre", "refuse", "erreur", "injoignable", "absent", "non_teste"), tag, chemin')];
}
