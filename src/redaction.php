<?php
declare(strict_types=1);

/**
 * Rédaction assistée : Claude (API Anthropic, Messages) propose les textes d'un post à partir des
 * vraies données de l'écran (produit, prix de la boutique, promotion et sa mention, gamme,
 * boutiques, langues) et de la charte éditoriale de la marque (Paramètres → Rédaction IA).
 *
 * L'appel part du serveur seulement : la clé (réglage secret `anthropic_api_key`) ne quitte jamais
 * le serveur. Pas de SDK : le module n'a pas de dépendance (comme Meta et TFBuddy, un curl).
 * Modèle : le réglage `ia_modele`, sinon le plus récent de la famille Opus que la clé peut appeler
 * (Models API, relu une fois par jour). Sortie JSON imposée par un schéma (output_config.format) ;
 * un refus du modèle est repris par le repli serveur (fallbacks « default »).
 */

final class SocIaError extends RuntimeException
{
    public function __construct(string $m, public int $http = 0) { parent::__construct($m); }
}

final class SocIa
{
    /** @var null|callable(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} — la recette */
    public static $transport = null;

    public static function cle(): string { return trim((string) (socSetting('anthropic_api_key') ?? '')); }

    public static function configured(): bool { return self::cle() !== ''; }

    /** Une requête à l'API Anthropic ; rend le JSON décodé ou lève une erreur lisible. */
    public static function http(string $method, string $path, ?array $body = null, array $betas = []): array
    {
        if (!self::configured()) { throw new SocIaError('clé API Anthropic absente (Paramètres → Rédaction IA)', 409); }
        $url = 'https://api.anthropic.com/v1/' . ltrim($path, '/');
        $h = ['x-api-key: ' . self::cle(), 'anthropic-version: 2023-06-01', 'content-type: application/json', 'accept: application/json'];
        if ($betas) { $h[] = 'anthropic-beta: ' . implode(',', $betas); }
        $json = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (self::$transport) {
            [$code, $raw] = (self::$transport)($method, $url, $h, $json);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
            if ($json !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $json); }
            $r = curl_exec($ch);
            $code = $r === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = $r === false ? curl_error($ch) : '';
            curl_close($ch);
            $raw = $r === false ? '' : (string) $r;
            if ($code === 0) { throw new SocIaError('API Anthropic injoignable' . ($err !== '' ? ' (' . $err . ')' : ''), 502); }
        }
        $j = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';
            throw new SocIaError(match (true) {
                $code === 401 || $code === 403 => 'clé API Anthropic refusée (Paramètres → Rédaction IA)',
                $code === 429 => 'trop de demandes à l’API Anthropic : réessayez dans un instant',
                $code === 529 || $code >= 500 => 'API Anthropic momentanément indisponible : réessayez',
                default => 'API Anthropic : requête refusée (HTTP ' . $code . ($msg !== '' ? ' — ' . $msg : '') . ')',
            }, $code);
        }
        return is_array($j) ? $j : [];
    }

    /** Le modèle : le réglage `ia_modele`, sinon le plus récent de la famille Opus accessible à la clé. */
    public static function modele(bool $forcer = false): string
    {
        $m = trim((string) (socSetting('ia_modele') ?? ''));
        if ($m !== '') { return $m; }
        $c = socSetting('ia_modele_auto');
        if (!$forcer && is_array($c) && ($c['le'] ?? 0) > time() - 86400 && !empty($c['id'])) { return (string) $c['id']; }
        $r = self::http('GET', 'models?limit=100');
        // La liste est rendue de la plus récente à la plus ancienne.
        $ids = array_values(array_filter(array_map(static fn ($x) => is_array($x) ? (string) ($x['id'] ?? '') : '', (array) ($r['data'] ?? []))));
        $id = '';
        foreach ($ids as $i) { if (str_starts_with($i, 'claude-opus-')) { $id = $i; break; } }
        $id = $id !== '' ? $id : ($ids[0] ?? '');
        if ($id === '') { throw new SocIaError('aucun modèle disponible pour cette clé', 409); }
        socSettingPut('ia_modele_auto', ['id' => $id, 'le' => time()], false);
        return $id;
    }

    /**
     * Un message à sortie JSON (schéma imposé) ; rend le JSON décodé. Effort moyen : des textes
     * courts, soignés, en une vingtaine de secondes.
     */
    public static function json(string $system, string $user, array $schema, int $max = 16000): array
    {
        $modele = self::modele();
        $body = ['model' => $modele, 'max_tokens' => $max, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]]];
        $betas = [];
        // Un refus des garde-fous est repris par le modèle de repli recommandé par Anthropic.
        if (preg_match('/^claude-(opus|fable)-/', $modele)) { $body['fallbacks'] = 'default'; $betas[] = 'server-side-fallback-2026-07-01'; }
        $r = self::http('POST', 'messages', $body, $betas);
        $stop = (string) ($r['stop_reason'] ?? '');
        if ($stop === 'refusal') { throw new SocIaError('le modèle a refusé de rédiger ce texte : reformulez la demande', 422); }
        if ($stop === 'max_tokens') { throw new SocIaError('réponse interrompue (trop longue) : réessayez', 502); }
        foreach ((array) ($r['content'] ?? []) as $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'text') {
                $j = json_decode((string) ($b['text'] ?? ''), true);
                if (is_array($j)) { return $j; }
            }
        }
        throw new SocIaError('réponse de l’API Anthropic illisible', 502);
    }
}

/* --- charte éditoriale ------------------------------------------------------------------------ */

/** La charte éditoriale de la marque (réglage `charte_editoriale`), complétée par défaut. */
function socCharte(): array
{
    $d = ['ton' => 'Chaleureux, gourmand, simple et artisanal : on parle du produit, de la fraîcheur et du savoir-faire de la boutique.',
        'vouvoiement' => true, 'emojisMax' => 2, 'motsInterdits' => [], 'hashtags' => ['#latelierby'], 'hashtagVille' => true,
        'longueur' => 125, 'signature' => ''];
    $c = socSetting('charte_editoriale');
    return is_array($c) ? array_replace($d, array_intersect_key($c, $d)) : $d;
}

/** Nettoie une charte reçue de l'écran : champs connus, types et longueurs bornés. */
function socCharteNettoyer(mixed $c): array
{
    $c = is_array($c) ? $c : [];
    $liste = static function (mixed $v, int $n, int $l): array {
        $v = is_array($v) ? $v : preg_split('/[,;\n]+/', (string) $v);
        return array_slice(array_values(array_filter(array_map(static fn ($x) => trim(mb_substr((string) $x, 0, $l)), (array) $v), static fn ($x) => $x !== '')), 0, $n);
    };
    return ['ton' => trim(mb_substr((string) ($c['ton'] ?? ''), 0, 600)),
        'vouvoiement' => !array_key_exists('vouvoiement', $c) || (bool) $c['vouvoiement'],
        'emojisMax' => max(0, min(10, (int) ($c['emojisMax'] ?? 2))),
        'motsInterdits' => $liste($c['motsInterdits'] ?? [], 60, 40),
        'hashtags' => array_map(static fn ($h) => '#' . ltrim(preg_replace('/\s+/u', '', $h), '#'), $liste($c['hashtags'] ?? [], 10, 40)),
        'hashtagVille' => !array_key_exists('hashtagVille', $c) || (bool) $c['hashtagVille'],
        'longueur' => max(60, min(400, (int) ($c['longueur'] ?? 125))),
        'signature' => trim(mb_substr((string) ($c['signature'] ?? ''), 0, 200))];
}

/* --- propositions de textes ------------------------------------------------------------------- */

/**
 * Trois propositions de texte (FR et/ou NL) pour un post, avec titre, sur-titre et phrase du visuel.
 * `$b` : produit, produitNl, prix, promo, mention, gamme, categorie, type, boutiques[], langues[],
 * lien (bool), consigne (court | gourmand | urgent | ''), texte (le texte actuel, pour une réécriture).
 */
function socRedactionProposer(array $b): array
{
    $ch = socCharte();
    $str = static fn (string $k, int $n = 300) => trim(mb_substr((string) ($b[$k] ?? ''), 0, $n));
    $langues = array_values(array_intersect(['fr', 'nl'], array_map('strval', (array) ($b['langues'] ?? ['fr'])))) ?: ['fr'];
    $boutiques = array_slice(array_values(array_filter(array_map(static fn ($x) => trim(mb_substr((string) $x, 0, 60)), (array) ($b['boutiques'] ?? [])))), 0, 30);
    $consigne = in_array($b['consigne'] ?? '', ['court', 'gourmand', 'urgent'], true) ? (string) $b['consigne'] : '';
    $faits = array_filter([
        'Produit' => $str('produit', 120), 'Nom néerlandais du produit' => $str('produitNl', 120), 'Catégorie' => $str('categorie', 80),
        'Gamme' => $str('gamme', 80), 'Prix de vente' => $str('prix', 40), 'Promotion' => $str('promo', 300),
        'Mention de l’offre (à reprendre telle quelle)' => $str('mention', 300), 'Type de post' => $str('type', 60),
        'Boutiques visées' => $boutiques ? implode(', ', $boutiques) : '', 'Texte actuel (à réécrire)' => $str('texte', 2000),
    ], static fn ($v) => $v !== '');

    $system = implode("\n", [
        'Tu es le rédacteur des réseaux sociaux (Facebook, Instagram) de L’Atelier By, réseau belge de boulangeries-pâtisseries artisanales en franchise.',
        'Charte éditoriale de la marque :',
        '- Ton : ' . ($ch['ton'] !== '' ? $ch['ton'] : 'chaleureux et gourmand') . '.',
        '- ' . ($ch['vouvoiement'] ? 'Vouvoiement (jamais de tutoiement).' : 'Tutoiement.'),
        '- Au plus ' . $ch['emojisMax'] . ' emoji(s) par texte.',
        $ch['motsInterdits'] ? '- Mots et expressions à ne jamais employer : ' . implode(', ', $ch['motsInterdits']) . '.' : '',
        '- La première phrase accroche et tient en ' . $ch['longueur'] . ' caractères (ce qui s’affiche avant « Voir plus »).',
        $ch['signature'] !== '' ? '- Signature à placer en fin de texte : « ' . $ch['signature'] . ' ».' : '',
        'Règles :',
        '- N’invente aucun fait : prix, date, promotion, ingrédient, horaire, adresse ou récompense absents des données fournies ne doivent pas apparaître.',
        '- Le texte part dans plusieurs boutiques : écris {ville} pour le nom de la boutique (jamais un nom de ville en dur) ; {horaires} pour ses horaires si utile.',
        !empty($b['lien']) ? '- Termine par la variable {lien} seule sur la dernière ligne (elle devient « Commandez ici 👉 » et l’adresse du webshop).' : '- Pas de lien.',
        '- Le néerlandais est celui de Belgique (Flandre, Bruxelles), naturel et idiomatique, pas une traduction mot à mot.',
        '- Pas de hashtags dans les textes : ils sont rendus à part.',
        '- Le titre du visuel fait 2 à 5 mots (il s’affiche en grandes capitales) ; le sur-titre 2 à 6 mots ; la phrase manuscrite 4 à 10 mots.',
    ]);
    $user = "Données du post :\n" . implode("\n", array_map(static fn ($k, $v) => '- ' . $k . ' : ' . $v, array_keys($faits), $faits))
        . "\n\nLangues à rédiger : " . implode(' et ', array_map(static fn ($l) => $l === 'nl' ? 'néerlandais' : 'français', $langues))
        . ($consigne !== '' ? "\nConsigne : réécris le texte actuel en version " . ['court' => 'plus courte (2 phrases au plus)', 'gourmand' => 'plus gourmande et sensorielle', 'urgent' => 'plus urgente (offre limitée, passez vite)'][$consigne] . '.' : '')
        . "\n\nPropose 3 variantes d’angle différent (par exemple : gourmandise, moment de vie, offre). Pour une langue non demandée, laisse le champ vide."
        . "\nPropose aussi 3 à 5 hashtags thématiques (sans # de ville).";
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['variantes', 'hashtags'], 'properties' => [
        'variantes' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['angle', 'fr', 'nl', 'titre', 'titreNl', 'surtitre', 'phrase'],
            'properties' => ['angle' => ['type' => 'string'], 'fr' => ['type' => 'string'], 'nl' => ['type' => 'string'], 'titre' => ['type' => 'string'],
                'titreNl' => ['type' => 'string'], 'surtitre' => ['type' => 'string'], 'phrase' => ['type' => 'string']]]],
        'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']]]];
    try {
        $j = SocIa::json($system, $user, $schema);
    } catch (SocIaError $e) {
        throw new HttpError($e->http >= 400 && $e->http < 600 ? ($e->http === 401 || $e->http === 403 ? 409 : $e->http) : 502, $e->getMessage());
    }
    $txt = static fn ($v, int $n) => trim(mb_substr((string) $v, 0, $n));
    $variantes = [];
    foreach (array_slice((array) ($j['variantes'] ?? []), 0, 3) as $v) {
        if (!is_array($v)) { continue; }
        $variantes[] = ['angle' => $txt($v['angle'] ?? '', 60), 'fr' => in_array('fr', $langues, true) ? $txt($v['fr'] ?? '', 2200) : '',
            'nl' => in_array('nl', $langues, true) ? $txt($v['nl'] ?? '', 2200) : '', 'titre' => $txt($v['titre'] ?? '', 60),
            'titreNl' => $txt($v['titreNl'] ?? '', 60), 'surtitre' => $txt($v['surtitre'] ?? '', 60), 'phrase' => $txt($v['phrase'] ?? '', 120)];
    }
    if (!$variantes) { throw new HttpError(502, 'aucune proposition reçue : réessayez'); }
    $tags = array_slice(array_values(array_unique(array_filter(array_map(static fn ($h) => '#' . ltrim(preg_replace('/[^\p{L}\p{N}_]/u', '', (string) $h), '#'), (array) ($j['hashtags'] ?? [])), static fn ($h) => $h !== '#'))), 0, 6);
    return ['variantes' => $variantes, 'hashtags' => array_values(array_unique(array_merge($ch['hashtags'], $tags))), 'hashtagVille' => $ch['hashtagVille'], 'modele' => SocIa::modele()];
}

/** L'état de la rédaction IA pour l'écran : clé présente, modèle retenu (sans appel). */
function socIaStatut(): array
{
    $auto = socSetting('ia_modele_auto');
    return ['configure' => SocIa::configured(), 'modele' => trim((string) (socSetting('ia_modele') ?? '')) ?: (is_array($auto) ? (string) ($auto['id'] ?? '') : '')];
}
