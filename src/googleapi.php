<?php
declare(strict_types=1);

/**
 * Les API Google — fiches des boutiques (Business Profile), Google Ads, Google Analytics 4 — : le seul point du module
 * qui leur parle (les avis lus par Google Places restent ceux du BO, src/google.php).
 *
 *  1. Un seul compte Google, autorisé une fois (OAuth) : l'identifiant du client OAuth (google_client_id), sa clé
 *     secrète (google_client_secret) et le jeton de rafraîchissement (google_refresh_token) sont dans
 *     `social_parametre`, les deux derniers marqués secrets : jamais renvoyés au navigateur. Le jeton d'accès (une
 *     heure) se redemande à Google et reste en base, secret lui aussi.
 *     Le jeton de rafraîchissement s'obtient une fois hors du module (OAuth Playground, docs/GOOGLE.md) : Google
 *     refuse une adresse de retour en IP ou en http, celle du serveur aujourd'hui.
 *  2. Google Ads : plus de jeton développeur depuis le 9 septembre 2026 ; le niveau d'accès (Explorer, Basic…) est
 *     celui du projet Google Cloud du client OAuth. `login-customer-id` : le compte administrateur, s'il y en a un.
 *  3. Tout appel passe par `call()` : l'erreur de Google est gardée telle quelle (statut, message), un 401 redemande
 *     le jeton d'accès une fois, un 429 / 503 se rejoue avec un délai qui double.
 *
 * `$transport` s'injecte pour les tests : un Google simulé rend les mêmes tableaux que le vrai.
 */
final class GoogleError extends RuntimeException
{
    public function __construct(string $message, public int $http = 0, public string $statut = '', public bool $transient = false)
    {
        parent::__construct($message);
    }
}

final class GoogleApi
{
    /** @var null|callable(string $method, string $url, array $headers, ?string $body): array{0:int,1:?array} */
    public static $transport = null;
    /** @var null|callable(int $s): void */
    public static $sleep = null;

    /** Les autorisations demandées : fiches des boutiques, Google Ads, Analytics (lecture). */
    public const SCOPES = [
        'fiches' => 'https://www.googleapis.com/auth/business.manage',
        'ads' => 'https://www.googleapis.com/auth/adwords',
        'ga4' => 'https://www.googleapis.com/auth/analytics.readonly',
    ];
    public const ADS_VERSION = 'v26';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Les réglages Google, lus dans social_parametre (et seulement là). */
    public static function config(): array
    {
        $s = static fn (string $k) => trim((string) (socSetting($k) ?? ''));
        return [
            'clientId' => $s('google_client_id'),
            'clientSecret' => $s('google_client_secret'),
            'refreshToken' => $s('google_refresh_token'),
            // Le compte administrateur Google Ads (10 chiffres), s'il y en a un : en-tête login-customer-id.
            'mcc' => preg_replace('/\D/', '', $s('google_ads_mcc')),
            'adsVersion' => preg_match('/^v\d{2}$/', $s('google_ads_version')) ? $s('google_ads_version') : self::ADS_VERSION,
        ];
    }

    public static function configure(): bool
    {
        $c = self::config();
        return $c['clientId'] !== '' && $c['clientSecret'] !== '' && $c['refreshToken'] !== '';
    }

    /** L'état pour l'écran : l'existence des secrets, jamais leur valeur. */
    public static function statut(): array
    {
        $c = self::config();
        return ['clientId' => $c['clientId'], 'secretDefini' => $c['clientSecret'] !== '', 'jetonDefini' => $c['refreshToken'] !== '',
            'configure' => self::configure(), 'mcc' => $c['mcc'], 'adsVersion' => $c['adsVersion'], 'verification' => socSetting('google_verification')];
    }

    /** Les clés saisies dans Paramètres → Google. Un champ vide n'efface rien. Rend les clés écrites. */
    public static function clesPut(array $in): array
    {
        $faits = [];
        $id = trim((string) ($in['clientId'] ?? ''));
        if ($id !== '') {
            if (!preg_match('/^[\w.-]{3,200}\.apps\.googleusercontent\.com$/', $id)) { throw new HttpError(422, 'L’identifiant du client OAuth se termine par « .apps.googleusercontent.com ».'); }
            socSettingPut('google_client_id', $id, false); $faits[] = 'google_client_id';
        }
        foreach (['clientSecret' => 'google_client_secret', 'refreshToken' => 'google_refresh_token'] as $k => $cle) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '') { socSettingPut($cle, $v, true); $faits[] = $cle; }
        }
        if (array_key_exists('mcc', $in)) {
            $mcc = preg_replace('/\D/', '', (string) $in['mcc']);
            if ($mcc !== '' && strlen($mcc) !== 10) { throw new HttpError(422, 'Le compte administrateur Google Ads a 10 chiffres (123-456-7890).'); }
            socSettingPut('google_ads_mcc', $mcc, false); $faits[] = 'google_ads_mcc';
        }
        // Nouvelle clé : le jeton d'accès gardé ne vaut plus.
        if (array_intersect($faits, ['google_client_id', 'google_client_secret', 'google_refresh_token'])) { socSettingPut('google_access_token', '', true); }
        return $faits;
    }

    /** Oublie la connexion : Google révoque le jeton, le module efface ceux qu'il gardait. */
    public static function deconnecter(): void
    {
        $r = self::config()['refreshToken'];
        if ($r !== '') {
            try { self::envoyer('POST', 'https://oauth2.googleapis.com/revoke', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $r])); }
            catch (\Throwable $e) { /* déjà révoqué ou Google injoignable : on efface quand même */ }
        }
        socSettingPut('google_refresh_token', '', true);
        socSettingPut('google_access_token', '', true);
        socSettingPut('google_verification', null, false);
    }

    /** Le jeton d'accès (une heure), redemandé à Google quand il expire. */
    public static function jeton(bool $forcer = false): string
    {
        $c = self::config();
        if (!self::configure()) { throw new GoogleError('Google n’est pas connecté : renseignez le client OAuth et le jeton de rafraîchissement (Paramètres → Google).', 0, 'NON_CONFIGURE'); }
        $garde = socSetting('google_access_token');
        if (!$forcer && is_string($garde) && $garde !== '') {
            $g = json_decode($garde, true);
            if (is_array($g) && ($g['exp'] ?? 0) > time() + 60 && ($g['pour'] ?? '') === hash('sha256', $c['refreshToken'])) { return (string) $g['t']; }
        }
        [$code, $j] = self::envoyer('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'refresh_token', 'client_id' => $c['clientId'], 'client_secret' => $c['clientSecret'], 'refresh_token' => $c['refreshToken']]));
        if ($code !== 200 || empty($j['access_token'])) {
            $e = (string) ($j['error'] ?? '');
            $msg = match ($e) {
                'invalid_grant' => 'Google refuse le jeton de rafraîchissement (révoqué, expiré ou créé pour un autre client OAuth). Une app Google en mode « Test » le fait expirer au bout de 7 jours : passez-la « En production », puis refaites le jeton (docs/GOOGLE.md).',
                'invalid_client', 'unauthorized_client' => 'Google refuse le client OAuth : vérifiez l’identifiant et la clé secrète (console Google Cloud → Identifiants).',
                default => 'Jeton Google illisible : ' . trim(($j['error_description'] ?? '') ?: ($e ?: 'HTTP ' . $code)),
            };
            throw new GoogleError($msg, $code, $e !== '' ? strtoupper($e) : 'JETON', $code === 0 || $code >= 500);
        }
        $t = (string) $j['access_token'];
        socSettingPut('google_access_token', json_encode(['t' => $t, 'exp' => time() + (int) ($j['expires_in'] ?? 3600), 'pour' => hash('sha256', $c['refreshToken'])]), true);
        return $t;
    }

    /**
     * Un appel à une API Google (JSON). `$query` part dans l'adresse, `$body` en JSON.
     * Google Ads : les en-têtes de compte s'ajoutent d'eux-mêmes pour googleads.googleapis.com.
     */
    public static function call(string $method, string $url, array $query = [], ?array $body = null): array
    {
        if ($query) { $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query); }
        $c = self::config(); $delai = 1; $renouvele = false;
        for ($essai = 0; $essai < 5; $essai++) {
            $h = ['Authorization: Bearer ' . self::jeton(), 'Accept: application/json'];
            if ($body !== null) { $h[] = 'Content-Type: application/json'; }
            if (str_contains($url, 'googleads.googleapis.com') && $c['mcc'] !== '') { $h[] = 'login-customer-id: ' . $c['mcc']; }
            [$code, $j] = self::envoyer($method, $url, $h, $body !== null ? (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
            if ($code >= 200 && $code < 300) { return is_array($j) ? $j : []; }
            if ($code === 401 && !$renouvele) { self::jeton(true); $renouvele = true; continue; }
            if (($code === 429 || $code === 503) && $essai < 3) { self::dormir($delai); $delai *= 2; continue; }
            throw self::erreur($code, $j, $url);
        }
        throw new GoogleError('Google répond trop lentement (limite d’appels)', 429, 'RESOURCE_EXHAUSTED', true);
    }

    /** Une requête GAQL sur un compte Google Ads, toutes les pages. */
    public static function ads(string $client, string $gaql): array
    {
        $client = preg_replace('/\D/', '', $client);
        $url = 'https://googleads.googleapis.com/' . self::config()['adsVersion'] . '/customers/' . $client . '/googleAds:search';
        $out = []; $page = null;
        do {
            $r = self::call('POST', $url, [], ['query' => $gaql] + ($page !== null ? ['pageToken' => $page] : []));
            foreach ((array) ($r['results'] ?? []) as $l) { $out[] = $l; }
            $page = isset($r['nextPageToken']) && $r['nextPageToken'] !== '' ? (string) $r['nextPageToken'] : null;
        } while ($page !== null && count($out) < 10000);
        return $out;
    }

    /** L'erreur de Google, dite pour l'écran (le cas des accès pas encore accordés est expliqué). */
    private static function erreur(int $code, ?array $j, string $url): GoogleError
    {
        $e = is_array($j['error'] ?? null) ? $j['error'] : [];
        $statut = (string) ($e['status'] ?? '');
        $msg = trim((string) ($e['message'] ?? ''));
        // Google Ads détaille l'erreur dans details[].errors[].
        foreach ((array) ($e['details'] ?? []) as $d) {
            foreach ((array) ($d['errors'] ?? []) as $x) {
                $msg = trim((string) ($x['message'] ?? $msg));
                $statut = (string) (array_values((array) ($x['errorCode'] ?? []))[0] ?? $statut);
                break 2;
            }
        }
        if ($code === 0) { return new GoogleError('Google injoignable : ' . ($msg ?: 'pas de réponse'), 0, 'INJOIGNABLE', true); }
        $api = str_contains($url, 'googleads.') ? 'ads' : (str_contains($url, 'analytics') ? 'ga4' : (str_contains($url, 'mybusiness') || str_contains($url, 'businessprofile') ? 'fiches' : ''));
        // Accès pas encore accordé : une API non activée, un projet pas encore validé (quota à 0), une autorisation manquante.
        $aide = match (true) {
            $statut === 'CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION' => ' → Le projet Google Cloud n’a que l’accès « Test » à Google Ads : demandez l’accès « Explorer » (console Google Cloud → Google Ads API → Présentation).',
            $api === 'fiches' && ($code === 429 || str_contains($msg, 'quota')) => ' → L’accès à l’API des fiches n’est pas encore accordé par Google (quota à 0) : la demande se fait par le formulaire Business Profile API (docs/GOOGLE.md).',
            $code === 403 && (str_contains($msg, 'has not been used') || str_contains($msg, 'is disabled') || $statut === 'SERVICE_DISABLED') => ' → Activez cette API dans le projet Google Cloud (Bibliothèque d’API).',
            $code === 403 && (str_contains($msg, 'scope') || $statut === 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') => ' → Le jeton n’a pas cette autorisation : refaites-le en cochant les trois autorisations (docs/GOOGLE.md).',
            default => '',
        };
        return new GoogleError(($msg !== '' ? $msg : 'HTTP ' . $code) . $aide, $code, $statut, $code >= 500);
    }

    /** @return array{0:int,1:?array} */
    private static function envoyer(string $method, string $url, array $headers, ?string $body): array
    {
        if (self::$transport) { return (self::$transport)($method, $url, $headers, $body); }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { return [0, ['error' => ['message' => $err]]]; }
        $j = json_decode((string) $raw, true);
        return [$code, is_array($j) ? $j : null];
    }

    private static function dormir(int $s): void
    {
        if (self::$sleep) { (self::$sleep)($s); return; }
        sleep($s);
    }

    /**
     * Ce que Google dit du jeton : le compte, les autorisations accordées, et un premier appel à chaque API (fiches,
     * Ads, Analytics) — ce qui marche déjà, ce qui attend un accès chez Google. Gardé pour l'écran.
     */
    public static function verifier(): array
    {
        $v = ['le' => date('Y-m-d H:i'), 'valide' => false, 'email' => '', 'autorisations' => [], 'apis' => [], 'erreur' => null];
        try {
            $t = self::jeton(true);
            [$code, $info] = self::envoyer('GET', 'https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($t), [], null);
            $scopes = $code === 200 ? preg_split('/\s+/', trim((string) ($info['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) : [];
            $v['valide'] = true;
            $v['email'] = (string) ($info['email'] ?? '');
            foreach (self::SCOPES as $k => $s) { $v['autorisations'][$k] = in_array($s, $scopes, true); }
            foreach (['fiches' => 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts', 'ads' => 'https://googleads.googleapis.com/' . self::config()['adsVersion'] . '/customers:listAccessibleCustomers',
                'ga4' => 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries'] as $k => $url) {
                if (!$v['autorisations'][$k]) { $v['apis'][$k] = ['ok' => false, 'erreur' => 'autorisation non accordée dans le jeton']; continue; }
                try { self::call('GET', $url, $k === 'ads' ? [] : ['pageSize' => 1]); $v['apis'][$k] = ['ok' => true, 'erreur' => null]; }
                catch (GoogleError $e) { $v['apis'][$k] = ['ok' => false, 'erreur' => $e->getMessage()]; }
            }
        } catch (GoogleError $e) {
            $v['erreur'] = $e->getMessage();
        }
        socSettingPut('google_verification', $v, false);
        return $v;
    }
}

/* --- Vos comptes Google : fiches, comptes Ads, propriétés GA4 ---------------------------------------------------- */

/**
 * Ce que le compte Google connecté peut gérer : les fiches des boutiques (Business Profile), les comptes Google Ads,
 * les propriétés Google Analytics 4. Chaque API à part : l'une peut attendre son accès chez Google sans bloquer les
 * autres. Gardé (google_decouverte) pour l'écran de rattachement.
 */
function socGapiDecouvrir(): array
{
    $out = ['le' => date('Y-m-d H:i'), 'fiches' => [], 'ads' => [], 'ga4' => [], 'erreurs' => []];
    $pages = static function (string $url, array $q, string $cle): array {
        $l = []; $page = null;
        do {
            $r = GoogleApi::call('GET', $url, $q + ($page !== null ? ['pageToken' => $page] : []));
            foreach ((array) ($r[$cle] ?? []) as $x) { $l[] = $x; }
            $page = ($r['nextPageToken'] ?? '') !== '' ? (string) $r['nextPageToken'] : null;
        } while ($page !== null && count($l) < 2000);
        return $l;
    };
    try {
        foreach ($pages('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', ['pageSize' => 20], 'accounts') as $a) {
            $compte = (string) ($a['name'] ?? '');
            if (!preg_match('#^accounts/\d+$#', $compte)) { continue; }
            foreach ($pages('https://mybusinessbusinessinformation.googleapis.com/v1/' . $compte . '/locations', ['pageSize' => 100, 'readMask' => 'name,title,storefrontAddress,metadata'], 'locations') as $l) {
                if (!preg_match('#^locations/(\d+)$#', (string) ($l['name'] ?? ''), $m)) { continue; }
                $ad = (array) ($l['storefrontAddress'] ?? []);
                $out['fiches'][] = ['id' => $compte . '/locations/' . $m[1], 'nom' => (string) ($l['title'] ?? ''),
                    'adresse' => trim(implode(', ', array_filter([implode(' ', (array) ($ad['addressLines'] ?? [])), trim(($ad['postalCode'] ?? '') . ' ' . ($ad['locality'] ?? ''))]))),
                    'placeId' => (string) ($l['metadata']['placeId'] ?? ''), 'compte' => (string) ($a['accountName'] ?? '')];
            }
        }
    } catch (GoogleError $e) { $out['erreurs']['fiches'] = $e->getMessage(); }
    try {
        $r = GoogleApi::call('GET', 'https://googleads.googleapis.com/' . GoogleApi::config()['adsVersion'] . '/customers:listAccessibleCustomers');
        foreach ((array) ($r['resourceNames'] ?? []) as $rn) {
            if (!preg_match('#^customers/(\d{10})$#', (string) $rn, $m)) { continue; }
            $c = ['id' => $m[1], 'nom' => '', 'devise' => '', 'administrateur' => false];
            try {
                $l = GoogleApi::ads($m[1], 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.manager FROM customer LIMIT 1')[0]['customer'] ?? [];
                $c = ['id' => $m[1], 'nom' => (string) ($l['descriptiveName'] ?? ''), 'devise' => (string) ($l['currencyCode'] ?? ''), 'administrateur' => !empty($l['manager'])];
            } catch (GoogleError $e) { $c['erreur'] = $e->getMessage(); }
            $out['ads'][] = $c;
        }
    } catch (GoogleError $e) { $out['erreurs']['ads'] = $e->getMessage(); }
    try {
        foreach ($pages('https://analyticsadmin.googleapis.com/v1beta/accountSummaries', ['pageSize' => 200], 'accountSummaries') as $a) {
            foreach ((array) ($a['propertySummaries'] ?? []) as $p) {
                if (!preg_match('#^properties/(\d+)$#', (string) ($p['property'] ?? ''), $m)) { continue; }
                $out['ga4'][] = ['id' => $m[1], 'nom' => (string) ($p['displayName'] ?? ''), 'compte' => (string) ($a['displayName'] ?? '')];
            }
        }
    } catch (GoogleError $e) { $out['erreurs']['ga4'] = $e->getMessage(); }
    socSettingPut('google_decouverte', $out, false);
    return $out;
}

/* --- Rattachement : chaque boutique, sa fiche et son compte Ads ; le réseau, son compte Ads et la propriété GA4 ----- */

/** Les rattachements enregistrés : ['boutiques' => [id => ['fiche', 'ads']], 'reseau' => ['ads', 'ga4']]. */
function socGapiIds(): array
{
    $g = socSetting('google_ids');
    $g = is_array($g) ? $g : [];
    return ['boutiques' => is_array($g['boutiques'] ?? null) ? $g['boutiques'] : [], 'reseau' => ['ads' => (string) ($g['reseau']['ads'] ?? ''), 'ga4' => (string) ($g['reseau']['ga4'] ?? '')]];
}

/** Enregistre les rattachements, bornés : boutiques du réseau (et magasins test), identifiants au bon format. */
function socGapiIdsPut(array $in): array
{
    $connues = array_map('strval', array_column(socShops(), 'id'));
    $fiche = static fn ($v) => preg_match('#^accounts/\d+/locations/\d+$#', (string) $v) ? (string) $v : '';
    $ads = static fn ($v) => strlen($d = preg_replace('/\D/', '', (string) $v)) === 10 ? $d : '';
    $out = ['boutiques' => [], 'reseau' => ['ads' => $ads($in['reseau']['ads'] ?? ''), 'ga4' => preg_match('/^\d{4,20}$/', (string) ($in['reseau']['ga4'] ?? '')) ? (string) $in['reseau']['ga4'] : '']];
    foreach ((array) ($in['boutiques'] ?? []) as $id => $b) {
        $id = (string) $id;
        if (!in_array($id, $connues, true) || !is_array($b)) { continue; }
        $x = ['fiche' => $fiche($b['fiche'] ?? ''), 'ads' => $ads($b['ads'] ?? '')];
        if ($x['fiche'] !== '' || $x['ads'] !== '') { $out['boutiques'][$id] = $x; }
    }
    socSettingPut('google_ids', $out, false);
    socLog(socUser(), 'Connexion', 'Google : rattachements enregistrés (' . count($out['boutiques']) . ' boutique(s))');
    return $out;
}

/**
 * Les fiches proposées pour chaque boutique pas encore rattachée : celle dont l'identifiant Google (place id) est
 * celui que la Réputation digitale du BO connaît pour la boutique, sinon celle dont le nom ou l'adresse la cite.
 * Rend [boutique => fiche].
 */
function socGapiSuggestions(?array $decouverte = null): array
{
    $d = $decouverte ?? socSetting('google_decouverte');
    $fiches = is_array($d) ? (array) ($d['fiches'] ?? []) : [];
    if (!$fiches) { return []; }
    $places = [];
    try { foreach ((array) (socGoogleBo()['fiches'] ?? []) as $sid => $f) { if (($f['placeId'] ?? '') !== '') { $places[(string) $sid] = (string) $f['placeId']; } } }
    catch (\Throwable $e) { /* la Réputation du BO illisible : on devine par le nom */ }
    $shops = socShops(); $out = []; $prises = [];
    foreach (socGapiIds()['boutiques'] as $b) { if (($b['fiche'] ?? '') !== '') { $prises[$b['fiche']] = true; } }
    foreach ($shops as $s) {
        $sid = (string) $s['id'];
        $f = null;
        if (isset($places[$sid])) { foreach ($fiches as $x) { if ($x['placeId'] !== '' && $x['placeId'] === $places[$sid]) { $f = $x; break; } } }
        if ($f === null) {
            foreach ($fiches as $x) { if (socDevinerBoutique($x['nom'] . ' ' . $x['adresse'], $shops) === $sid) { $f = $x; break; } }
        }
        if ($f !== null && empty($prises[$f['id']])) { $out[$sid] = $f['id']; }
    }
    return $out;
}

/** Tout ce que l'écran Paramètres → Google affiche. */
function socGapiEcran(): array
{
    return ['statut' => GoogleApi::statut(), 'decouverte' => socSetting('google_decouverte'), 'ids' => socGapiIds(), 'suggestions' => socGapiSuggestions()];
}
