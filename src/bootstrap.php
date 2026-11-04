<?php
declare(strict_types=1);

/**
 * Social & Ads — le socle : configuration, base, authentification, journal.
 *
 * Le module est INDÉPENDANT du BO consultant par son code (dépôt, dossier,
 * URL /social, déploiement à part) mais il en partage tout ce qui fait un
 * accès :
 *
 *  - la BASE : les identifiants MySQL sont lus dans le config/config.php du
 *    BO — un mot de passe changé là-bas vaut ici au chargement suivant ;
 *  - les SECRETS META : le même System User que Brand Guard (variable
 *    d'environnement d'abord, puis le bloc `meta` du config du BO) ;
 *  - l'AUTHENTIFICATION : le cookie de session du cockpit (`cockpit_s`, posé
 *    sur le chemin `/`) est vérifié ici avec le même secret
 *    (`ceo_app_setting.authSecret`). Connecté au BO = connecté au module ;
 *  - les BOUTIQUES : `ceo_shop`, les pages Facebook de Brand Guard
 *    (`brand_guard_pages`) et la caisse du panel (`shops`, `transaction`) ;
 *  - le JOURNAL : chaque geste s'écrit aussi dans `ceo_journal_entry`, il
 *    paraît dans l'écran Journal du cockpit.
 *
 * Les tables propres au module sont préfixées `social_` et créées au premier
 * appel (comme le BO, sans migration). Toutes les données ET les clés du module
 * (token Meta, secret de l'app…) y sont stockées et lues : `mar_sa_parametre`.
 */

const SOCIAL_VERSION = '1.0.0';

/** Configuration du module (config/config.php, sinon l'exemple). */
function socialConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $f = __DIR__ . '/../config/config.php';
        $cfg = is_file($f) ? require $f : require __DIR__ . '/../config/config.example.php';
        if (!is_array($cfg)) { $cfg = []; }
    }
    return $cfg;
}

/**
 * Les points d'accroche de l'hôte. Le module vit aujourd'hui à côté du BO consultant ;
 * pour le transporter (ERP…), l'hôte fournit `config/hote.php` (hors Git) qui rend
 * un tableau de fonctions — chacune remplace le comportement « BO » d'un seul point :
 *   pdo()                       → PDO          la base (sinon : identifiants du BO)
 *   auth()                      → bool         la session franchiseur (sinon : cookie du cockpit)
 *   utilisateur()               → string       le nom affiché dans le journal
 *   journal($at,$qui,$quoi,$d)  → void         le journal de l'hôte (sinon : ceo_journal_entry)
 *   boutiques(array $liste)     → array        les boutiques (reçoit la liste calculée, la rend)
 *   franchise_session()         → ?array       le franchisé déjà connecté à l'hôte :
 *                                              ['shops' => [ids], 'name' => '…'] ou null
 * Voir config/hote.example.php et docs/INTEGRATION-ERP.md.
 */
function socHote(string $point): ?callable
{
    static $fichier = null;
    if ($fichier === null) {
        $fichier = [];
        $f = __DIR__ . '/../config/hote.php';
        if (is_file($f)) { $x = require $f; if (is_array($x)) { $fichier = $x; } }
    }
    $h = (isset($GLOBALS['SOCIAL_HOTE']) && is_array($GLOBALS['SOCIAL_HOTE']) ? $GLOBALS['SOCIAL_HOTE'] : []) + $fichier;
    return isset($h[$point]) && is_callable($h[$point]) ? $h[$point] : null;
}

/** Le dossier du BO consultant. */
function boDir(): string
{
    return rtrim((string) (socialConfig()['boDir'] ?? '/var/www/consulant_bo'), '/');
}

/**
 * La configuration du BO (son config/config.php), lue sans l'exécuter deux
 * fois. Absente : un tableau vide — le module répond alors 503 sur les
 * routes qui ont besoin de la base, et le front bascule en démonstration.
 */
function boConfig(): array
{
    static $cfg = null;
    if ($cfg !== null) { return $cfg; }
    $cfg = [];
    foreach ([boDir() . '/config/config.php', boDir() . '/config/config.example.php'] as $f) {
        if (is_file($f) && is_readable($f)) {
            $c = (static fn (string $p) => require $p)($f);
            if (is_array($c)) { $cfg = $c; $cfg['_source'] = $f; break; }
        }
    }
    return $cfg;
}

final class Db
{
    private static ?PDO $pdo = null;

    /** Identifiants : surcharge du module, sinon ceux du BO, sinon l'environnement. */
    public static function params(): array
    {
        $o = socialConfig()['db'] ?? null;
        $b = boConfig()['db'] ?? [];
        $d = is_array($o) ? $o + $b : $b;
        return [
            'host'     => (string) (getenv('COCKPIT_DB_HOST') ?: ($d['host'] ?? '127.0.0.1')),
            'port'     => (string) (getenv('COCKPIT_DB_PORT') ?: ($d['port'] ?? '3306')),
            'name'     => (string) (getenv('COCKPIT_DB_NAME') ?: ($d['name'] ?? 'atelierby_db')),
            'user'     => (string) (getenv('COCKPIT_DB_USER') ?: ($d['user'] ?? '')),
            'password' => (string) (getenv('COCKPIT_DB_PASSWORD') ?: ($d['password'] ?? '')),
            'charset'  => (string) ($d['charset'] ?? 'utf8mb4'),
        ];
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) { return self::$pdo; }
        if (($f = socHote('pdo')) !== null) { return self::$pdo = $f(); }
        $p = self::params();
        $dsn = "mysql:host={$p['host']};port={$p['port']};dbname={$p['name']};charset={$p['charset']}";
        self::$pdo = new PDO($dsn, $p['user'], $p['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$pdo;
    }

    /** Pour les tests : une connexion fournie (base de recette). */
    public static function use(PDO $pdo): void { self::$pdo = $pdo; }

    public static function rows(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** La table existe-t-elle ? (les tables du BO et du panel peuvent manquer en recette) */
    /** La table existe-t-elle ? Réponse gardée pour la requête ; `$relire` la redemande (recette). */
    public static function hasTable(string $t, bool $relire = false): bool
    {
        static $memo = [];
        if ($relire) { unset($memo[$t]); }
        if (!isset($memo[$t])) {
            // information_schema plutôt que SHOW TABLES : ce dernier refuse les requêtes préparées natives.
            try { $memo[$t] = self::row('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]) !== null; }
            catch (Throwable $e) { $memo[$t] = false; }
        }
        return $memo[$t];
    }
}

/* --- réglages ------------------------------------------------------------------ */

/** Un réglage du BO (`ceo_app_setting`) — lecture seule depuis le module. */
function boSetting(string $key, mixed $default = null): mixed
{
    if (!Db::hasTable('ceo_app_setting')) { return $default; }
    $r = Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [$key]);
    return $r === null ? $default : json_decode((string) $r['value'], true);
}

/* --- réglages et clés : mar_variable, mar_keys (module « social ») --------------
 *
 * Le module se déplace avec l'ensemble Marketing : ses réglages vivent dans les tables communes
 * `mar_variable` (en clair) et ses secrets dans `mar_keys` (chiffrés), ligne `module = social`.
 * Même format que le module Marketing (Marketing\Support\Keys) : `x1:` XChaCha20-Poly1305 ou `g1:`
 * AES-256-GCM, données associées `mar_keys:social:{nom}`, même clé maîtresse — un fichier hors de la
 * base (`SOCIAL_KEYS_FILE`, sinon `MAR_KEYS_FILE`, sinon celui du module Marketing sur ce serveur).
 *
 * L'ancienne table (`mar_sa_parametre`, ex-`social_parametre`) reste en repli : un réglage pas encore
 * versé s'y lit encore, et une clé y reste tant qu'elle ne peut pas être chiffrée (clé maîtresse
 * illisible). `socMigrerParametres()` (schema.php) y verse tout au démarrage, sans rien écraser.
 */

final class SocCles
{
    private static ?string $master = null;
    private static bool $lue = false;

    public static function fichier(): string
    {
        return (string) (getenv('SOCIAL_KEYS_FILE') ?: (getenv('MAR_KEYS_FILE') ?: '/var/www/private/marketing/master.key'));
    }

    /** La clé maîtresse (32 octets), ou null si le fichier manque ou est illisible. */
    public static function maitresse(): ?string
    {
        if (self::$lue) { return self::$master; }
        self::$lue = true;
        $f = self::fichier();
        if (!is_readable($f)) { return null; }
        $c = trim((string) @file_get_contents($f));
        $k = strlen($c) === 64 && ctype_xdigit($c) ? hex2bin($c) : base64_decode($c, true);
        return self::$master = (is_string($k) && strlen($k) === 32) ? $k : null;
    }

    public static function oublier(): void { self::$master = null; self::$lue = false; }

    public static function chiffrer(string $clair, string $nom): ?string
    {
        $k = self::maitresse();
        if ($k === null) { return null; }
        $ad = 'mar_keys:social:' . $nom;
        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $n = random_bytes(24);
            return 'x1:' . base64_encode($n . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($clair, $ad, $n, $k));
        }
        $iv = random_bytes(12); $tag = '';
        $ct = openssl_encrypt($clair, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag, $ad, 16);
        return $ct === false ? null : 'g1:' . base64_encode($iv . $tag . $ct);
    }

    public static function dechiffrer(string $chiffre, string $nom): ?string
    {
        $k = self::maitresse();
        if ($k === null || strlen($chiffre) < 4 || $chiffre[2] !== ':') { return null; }
        $raw = base64_decode(substr($chiffre, 3), true);
        $ad = 'mar_keys:social:' . $nom;
        if ($raw === false) { return null; }
        if (str_starts_with($chiffre, 'x1:') && strlen($raw) > 24 && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            $p = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, 24), $ad, substr($raw, 0, 24), $k);
            return $p === false ? null : $p;
        }
        if (str_starts_with($chiffre, 'g1:') && strlen($raw) > 28) {
            $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $ad);
            return $p === false ? null : $p;
        }
        return null;
    }
}

/** Les réglages du module, lus une fois par requête (et relus après chaque écriture). */
function socParamsLus(bool $oublier = false): array
{
    static $cache = null;
    if ($oublier) { $cache = null; return []; }
    if ($cache !== null) { return $cache; }
    $cache = ['vars' => [], 'keys' => [], 'ancien' => []];
    if (Db::hasTable('mar_variable')) {
        foreach (Db::rows("SELECT name, value FROM mar_variable WHERE module = 'social'") as $r) { $cache['vars'][(string) $r['name']] = (string) $r['value']; }
    }
    if (Db::hasTable('mar_keys')) {
        foreach (Db::rows("SELECT name, ciphertext FROM mar_keys WHERE module = 'social'") as $r) { $cache['keys'][(string) $r['name']] = (string) $r['ciphertext']; }
    }
    if (Db::hasTable('mar_sa_parametre')) {
        foreach (Db::rows('SELECT cle, valeur, secret FROM mar_sa_parametre') as $r) { $cache['ancien'][(string) $r['cle']] = ['valeur' => (string) $r['valeur'], 'secret' => (int) $r['secret'] === 1]; }
    }
    return $cache;
}

/**
 * Un réglage du module (ou une clé d'accès : token Meta, secret de l'app, clé IA…). C'est la seule
 * source des clés du module — ni fichier, ni environnement.
 */
function socSetting(string $key, mixed $default = null): mixed
{
    $p = socParamsLus();
    if (isset($p['keys'][$key])) {
        $clair = SocCles::dechiffrer($p['keys'][$key], $key);
        if ($clair !== null) { return json_decode($clair, true); }
    }
    if (array_key_exists($key, $p['vars'])) { return json_decode($p['vars'][$key], true); }
    if (isset($p['ancien'][$key]) && $p['ancien'][$key]['valeur'] !== '') { return json_decode($p['ancien'][$key]['valeur'], true); }
    return $default;
}

/** Vrai si le réglage est une clé (jamais renvoyée au navigateur). */
function socSettingSecret(string $key): bool
{
    $p = socParamsLus();
    return isset($p['keys'][$key]) || (($p['ancien'][$key]['secret'] ?? false) === true);
}

/**
 * Écrit un réglage. `$secret` : true → clé chiffrée (mar_keys), false → réglage (mar_variable),
 * null → là où il est déjà. Sans clé maîtresse lisible, une clé reste dans l'ancienne table plutôt
 * que d'être écrite en clair dans mar_variable.
 */
function socSettingPut(string $key, mixed $value, ?bool $secret = null): void
{
    $secret ??= socSettingSecret($key);
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $nouvelles = Db::hasTable('mar_variable') && Db::hasTable('mar_keys')
        // Une valeur trop grande pour la colonne (un cache) reste dans l'ancienne table.
        && ($secret || !function_exists('socVariableMax') || strlen((string) $json) <= socVariableMax());
    $chiffre = $secret && $nouvelles ? SocCles::chiffrer($json, $key) : null;

    if ($nouvelles && (!$secret || $chiffre !== null)) {
        if ($secret) {
            $indice = is_string($value) && strlen($value) >= 12 ? '••••' . substr($value, -4) : null;
            Db::exec("INSERT INTO mar_keys (module, name, ciphertext, hint) VALUES ('social', ?, ?, ?)
                      ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), hint = VALUES(hint)", [$key, $chiffre, $indice]);
            Db::exec("DELETE FROM mar_variable WHERE module = 'social' AND name = ?", [$key]);
        } else {
            Db::exec("INSERT INTO mar_variable (module, name, value) VALUES ('social', ?, ?)
                      ON DUPLICATE KEY UPDATE value = VALUES(value)", [$key, $json]);
            Db::exec("DELETE FROM mar_keys WHERE module = 'social' AND name = ?", [$key]);
        }
        // Versé : l'ancienne ligne ne doit plus faire repli (ni garder une clé en clair).
        if (Db::hasTable('mar_sa_parametre')) { Db::exec('DELETE FROM mar_sa_parametre WHERE cle = ?', [$key]); }
    } else {
        Db::exec('INSERT INTO mar_sa_parametre (cle, valeur, secret, maj_le) VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), secret = VALUES(secret), maj_le = VALUES(maj_le)',
            [$key, $json, $secret ? 1 : 0, date('Y-m-d H:i:s')]);
        if ($secret && $nouvelles) { error_log('[social] clé « ' . $key . ' » gardée hors de mar_keys : clé maîtresse illisible (' . SocCles::fichier() . ')'); }
    }
    socParamsLus(true);
}

/** Retire un réglage, où qu'il soit. */
function socSettingDelete(string $key): void
{
    if (Db::hasTable('mar_variable')) { Db::exec("DELETE FROM mar_variable WHERE module = 'social' AND name = ?", [$key]); }
    if (Db::hasTable('mar_keys')) { Db::exec("DELETE FROM mar_keys WHERE module = 'social' AND name = ?", [$key]); }
    if (Db::hasTable('mar_sa_parametre')) { Db::exec('DELETE FROM mar_sa_parametre WHERE cle = ?', [$key]); }
    socParamsLus(true);
}

/** Les paramètres pour l'écran : les réglages en clair, les clés réduites à « définie ». */
function socParams(): array
{
    $p = socParamsLus();
    $out = [];
    foreach ($p['ancien'] as $cle => $r) {
        $out[$cle] = $r['secret'] ? ['defini' => json_decode($r['valeur'], true) !== '' && $r['valeur'] !== ''] : json_decode($r['valeur'], true);
    }
    foreach ($p['vars'] as $cle => $v) { $out[$cle] = json_decode($v, true); }
    foreach ($p['keys'] as $cle => $c) { $out[$cle] = ['defini' => SocCles::dechiffrer($c, $cle) !== null]; }
    ksort($out);
    return $out;
}

/* --- authentification partagée avec le cockpit --------------------------------- */

const BO_AUTH_COOKIE = 'cockpit_s';

/** L'écran de connexion du BO est-il actif ? (sinon accès ouvert, comme le BO) */
function authEnabled(): bool
{
    $o = socialConfig()['auth'] ?? null;
    if (is_bool($o)) { return $o; }
    return (bool) (boConfig()['auth'] ?? false);
}

/**
 * La session du cockpit est-elle valable ? Même algorithme que le BO
 * (src/auth.php) : `expiration.hmac_sha256(expiration, authSecret)`.
 */
function authOk(): bool
{
    if (($f = socHote('auth')) !== null) { return (bool) $f(); }
    return socAuthCheck(authEnabled());
}

function socAuthCheck(bool $enabled): bool
{
    if (!$enabled) { return true; }
    $c = (string) ($_COOKIE[BO_AUTH_COOKIE] ?? '');
    if ($c === '' || !str_contains($c, '.')) { return false; }
    $secret = (string) boSetting('authSecret', '');
    if ($secret === '' || boSetting('authPasswordHash') === null) { return false; }
    [$exp, $sig] = explode('.', $c, 2);
    if (!ctype_digit($exp) || (int) $exp < time()) { return false; }
    return hash_equals(hash_hmac('sha256', $exp, $secret), $sig);
}

/** Le nom affiché pour les gestes de l'écran. */
function socUser(): string
{
    if (($f = socHote('utilisateur')) !== null) { return (string) $f(); }
    $n = trim((string) (socSetting('utilisateur') ?? ''));
    return $n !== '' ? $n : (string) (socialConfig()['userName'] ?? 'Franchiseur');
}

/* --- journal ----------------------------------------------------------------------- */

/**
 * Trace un geste : dans `mar_sa_activite` (journal de l'écran Paramètres) et dans
 * `ceo_journal_entry` (écran Journal du cockpit). Le journal du BO est un
 * miroir best-effort : son absence ne bloque rien.
 */
function socLog(string $who, string $what, string $detail, ?string $at = null): void
{
    $at = $at ?? date('Y-m-d H:i:s');
    Db::exec('INSERT INTO mar_sa_activite (le, qui, quoi, detail) VALUES (?,?,?,?)',
        [$at, mb_substr($who, 0, 120), mb_substr($what, 0, 60), mb_substr($detail, 0, 1000)]);
    if (($f = socHote('journal')) !== null) {
        try { $f($at, $who, $what, $detail); } catch (Throwable $e) { /* miroir best-effort */ }
        return;
    }
    if (Db::hasTable('ceo_journal_entry')) {
        try {
            Db::exec('INSERT INTO ceo_journal_entry (happened_at, actor, kind, project, message) VALUES (?,?,?,?,?)',
                [$at, mb_substr($who, 0, 120), 'Social & Ads', '—', mb_substr($what . ' · ' . $detail, 0, 1000)]);
        } catch (Throwable $e) { /* miroir best-effort */ }
    }
}

/* --- requête HTTP --------------------------------------------------------------------- */

function body(): array
{
    static $b = null;
    if (array_key_exists('SOC_BODY', $GLOBALS)) { return (array) $GLOBALS['SOC_BODY']; }  // recette
    if ($b === null) {
        $raw = file_get_contents('php://input') ?: '';
        $b = json_decode($raw, true);
        if (!is_array($b)) { $b = []; }
    }
    return $b;
}

/** Une erreur métier, rendue en JSON avec son statut HTTP. */
final class HttpError extends RuntimeException
{
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}

/** Un corps de réponse binaire (une image) : son type MIME et ses octets. */
final class SocFichier
{
    public function __construct(public string $type, public string $octets) {}
}

/** Une réponse qui renvoie ailleurs (302) : le lien d'avis Google d'une boutique. */
final class SocRedirection
{
    public function __construct(public string $url) {}
}
