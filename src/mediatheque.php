<?php
declare(strict_types=1);

/**
 * La médiathèque commune du marketing, vue de Social.
 *
 * Les photos et les vidéos ne vivent plus dans le navigateur (IndexedDB) : elles sont
 * dans la médiathèque du module Marketing (`…/api/v1/marketing/media-assets`), commune
 * aux posts, aux publicités, aux campagnes, aux brochures et aux bâches. Social y passe
 * par son serveur, avec le jeton de service du Marketing (en-tête X-Marketing-Service) :
 * l'écran n'a ni le jeton ni l'adresse interne.
 *
 * Le module Marketing lui-même, pas la copie de son API logée dans le cockpit : les
 * fichiers sont écrits dans le dossier des envois de celui qui les reçoit, il n'en faut
 * qu'un. Adresse : le paramètre `marketing_media_api`, sinon le Marketing du serveur.
 */

final class MarketingMediaError extends RuntimeException
{
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}

final class MarketingMedia
{
    /** @var null|callable(string $method, string $url, list<string> $headers, ?string $body): array{0:int,1:string} — remplacé par la recette */
    public static $transport = null;

    public static function base(): string
    {
        return rtrim((string) (socSetting('marketing_media_api') ?: 'http://127.0.0.1/marketing/api/v1/marketing'), '/');
    }

    /**
     * Appelle l'API ; rend le corps décodé, ou lève l'erreur que le Marketing a donnée.
     *
     * @param array<string,mixed> $query
     */
    public static function call(string $method, string $path, array $query = [], ?string $body = null, ?string $type = null): array
    {
        $jeton = BoMarketing::jetonService();
        if ($jeton === null) {
            throw new MarketingMediaError(503, 'Médiathèque commune indisponible : le jeton de service du module Marketing est absent sur ce serveur.');
        }

        $url = self::base() . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Accept: application/json', 'X-Marketing-Service: ' . $jeton];
        if ($type !== null) { $headers[] = 'Content-Type: ' . $type; }
        // Les adresses des fichiers sont celles du public, pas celle par laquelle ce serveur se parle à lui-même.
        $publique = (string) (socSetting('url_publique') ?? '');
        $hote = parse_url($publique, PHP_URL_HOST);
        if (is_string($hote) && $hote !== '') {
            $port = parse_url($publique, PHP_URL_PORT);
            $headers[] = 'X-Forwarded-Host: ' . $hote . ($port ? ':' . $port : '');
            $headers[] = 'X-Forwarded-Proto: ' . (parse_url($publique, PHP_URL_SCHEME) === 'http' ? 'http' : 'https');
        }

        if (self::$transport) {
            [$code, $texte] = (self::$transport)($method, $url, $headers, $body);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
            $r = curl_exec($ch);
            $code = $r === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = $r === false ? curl_error($ch) : '';
            curl_close($ch);
            $texte = $r === false ? '' : (string) $r;
            if ($code === 0) {
                throw new MarketingMediaError(503, 'Médiathèque commune injoignable (' . self::base() . ($err !== '' ? ' : ' . $err : '') . ').');
            }
        }

        $j = json_decode($texte, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($j) ? (string) ($j['error']['message'] ?? $j['description'] ?? '') : '';
            $details = is_array($j['error']['details'] ?? null) ? array_filter(array_map(static fn ($d) => is_array($d) ? (string) ($d['message'] ?? '') : '', $j['error']['details'])) : [];
            $details = array_values(array_diff($details, [$msg]));
            throw new MarketingMediaError($code === 401 ? 503 : $code, trim(($msg !== '' ? $msg : 'Médiathèque commune : HTTP ' . $code) . ($details ? ' ' . implode(' ', $details) : '')));
        }
        if (!is_array($j)) {
            throw new MarketingMediaError(502, 'Médiathèque commune : réponse illisible.');
        }
        return $j;
    }
}

/** Les paramètres de liste que l'écran peut passer (le Marketing valide le reste). */
function socMediathequeListe(array $get): array
{
    $q = array_intersect_key($get, array_flip(['page', 'perPage', 'sort', 'filter']));
    return MarketingMedia::call('GET', 'media-assets', $q);
}

function socMediathequeBibliotheque(): array
{
    return MarketingMedia::call('GET', 'media-library');
}

/** Le corps brut de la requête (un fichier envoyé tel quel) ; la recette le pose dans SOC_RAW. */
function socCorpsBrut(): string
{
    return array_key_exists('SOC_RAW', $GLOBALS) ? (string) $GLOBALS['SOC_RAW'] : (string) (file_get_contents('php://input') ?: '');
}

/** Vrai si la requête porte un fichier en corps brut (image/…, video/…). */
function socEnvoiBrut(): bool
{
    $type = strtolower((string) ($GLOBALS['SOC_RAW_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? ''));
    return (bool) preg_match('#^(image|video)/#', $type);
}

/**
 * Dépose une photo ou une vidéo.
 *
 * Deux formes : le fichier en corps brut (l'original, tel que pris — une bâche en a
 * besoin) avec ses classements en query ; ou du JSON `{content: "data:…", poster?, …}`,
 * la forme que le studio manipule déjà. Dans les deux cas, le fichier part au Marketing
 * sans base64 ; une vidéo reçoit ensuite la couverture tirée par le navigateur.
 */
function socMediathequeDeposer(array $b): array
{
    if (socEnvoiBrut()) {
        $type = strtolower(trim(explode(';', (string) ($GLOBALS['SOC_RAW_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        $bin = socCorpsBrut();
        $champs = $_GET;
        $poster = null;
    } else {
        $content = (string) ($b['content'] ?? '');
        if (!preg_match('#^data:([a-z]+/[a-z0-9.+-]+);base64,#i', $content, $m)) {
            throw new MarketingMediaError(422, 'Fichier attendu en data:…;base64, ou en corps brut.');
        }
        $bin = base64_decode(substr($content, strlen($m[0])), true);
        $type = preg_match('#^(image|video)/#i', $m[1]) ? strtolower($m[1]) : 'application/octet-stream';
        $champs = $b;
        $poster = (string) ($b['poster'] ?? '');
    }
    if ($bin === false || $bin === '') {
        throw new MarketingMediaError(422, 'Fichier vide ou illisible. Si le fichier est volumineux, le serveur a pu refuser l\'envoi.');
    }

    $q = ['source' => 'social'];
    foreach (['name', 'category', 'subcategory', 'season'] as $k) {
        if (isset($champs[$k]) && is_string($champs[$k]) && $champs[$k] !== '') { $q[$k] = $champs[$k]; }
    }
    if (isset($champs['cutout'])) { $q['cutout'] = filter_var($champs['cutout'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false'; }

    $r = MarketingMedia::call('POST', 'media-assets', $q, $bin, $type);
    $media = (array) ($r['data'] ?? []);

    if ($poster !== null && ($media['kind'] ?? '') === 'video' && empty($media['thumbnailUrl']) && preg_match('#^data:(image/[a-z+.-]+);base64,#i', $poster, $pm)) {
        $img = base64_decode(substr($poster, strlen($pm[0])), true);
        if ($img !== false && $img !== '') {
            try {
                $media = (array) (MarketingMedia::call('PUT', 'media-assets/' . rawurlencode((string) $media['id']) . '/thumbnail', [], $img, strtolower($pm[1]))['data'] ?? $media);
            } catch (MarketingMediaError $e) {
                // Sans couverture, la vidéo reste déposée : l'écran montre un pictogramme.
            }
        }
    }
    return ['data' => $media, 'duplicate' => !empty($r['meta']['duplicate'])];
}

/** La couverture d'une vidéo, en corps brut (image tirée par le navigateur). */
function socMediathequeCouverture(string $id): array
{
    $type = strtolower(trim(explode(';', (string) ($GLOBALS['SOC_RAW_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if (!preg_match('#^image/(jpeg|png|webp)$#', $type)) {
        throw new MarketingMediaError(422, 'Couverture attendue en image (JPEG, PNG, WebP).');
    }
    return MarketingMedia::call('PUT', 'media-assets/' . rawurlencode($id) . '/thumbnail', [], socCorpsBrut(), $type);
}

/** Renomme ou reclasse ; `archived: true` retire de la médiathèque (le fichier reste servi). */
function socMediathequeModifier(string $id, array $b): array
{
    $champs = array_intersect_key($b, array_flip(['name', 'category', 'subcategory', 'season', 'cutout', 'archived']));
    return MarketingMedia::call('PATCH', 'media-assets/' . rawurlencode($id), [], (string) json_encode($champs, JSON_UNESCAPED_UNICODE), 'application/json');
}

function socMediathequeRetirer(string $id): array
{
    return MarketingMedia::call('DELETE', 'media-assets/' . rawurlencode($id));
}
