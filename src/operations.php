<?php
declare(strict_types=1);

/**
 * Les opérations = les campagnes commerciales du Marketing du BO consultant,
 * lues par son API (`GET {api}/campaigns`, `GET {api}/campaigns/{id}`,
 * `GET {api}/shops`). Le module ne les crée ni ne les modifie : il y rattache
 * ses posts et ses publicités (identifiant `bo-{id}`) et en calcule le retour.
 *
 * Rien n'est copié en base : chaque demande relit l'API (une fois par requête).
 * Adresse de l'API : paramètre `marketing_api` (Paramètres), par défaut celle du
 * BO installé sur le même serveur.
 */

final class BoMarketingError extends RuntimeException {}

final class BoMarketing
{
    /** @var null|callable(string $url): array{0:int,1:string} — remplacé par la recette */
    public static $transport = null;

    private static ?string $retenue = null;

    /** Les adresses possibles : le paramètre, puis le BO joint par l'adresse publique du serveur. */
    public static function candidates(): array
    {
        $l = [rtrim((string) (socSetting('marketing_api') ?: 'http://127.0.0.1/consulant_bo/api/v1/marketing'), '/')];
        $hote = parse_url((string) (socSetting('url_publique') ?? ''), PHP_URL_HOST);
        if (is_string($hote) && $hote !== '') { $l[] = 'http://' . $hote . '/' . trim((string) (socSetting('url_bo') ?? '/consulant_bo/'), '/') . '/api/v1/marketing'; }
        return array_values(array_unique($l));
    }

    public static function base(): string
    {
        return self::$retenue ?? self::candidates()[0];
    }

    /** GET JSON sur l'API marketing du BO (lecture seule) ; la première adresse qui répond est retenue. */
    public static function get(string $path): mixed
    {
        if (self::$retenue === null && !self::$transport) {
            $err = null;
            foreach (self::candidates() as $b) {
                self::$retenue = $b;
                try { return self::lire($path); } catch (BoMarketingError $e) { $err ??= $e; self::$retenue = null; }
            }
            throw $err ?? new BoMarketingError('API Marketing du BO : aucune adresse');
        }
        return self::lire($path);
    }

    private static function lire(string $path): mixed
    {
        $url = self::base() . '/' . ltrim($path, '/');
        if (self::$transport) {
            [$code, $b] = (self::$transport)($url);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            $r = curl_exec($ch);
            $code = $r === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = $r === false ? curl_error($ch) : '';
            curl_close($ch);
            $b = $r === false ? '' : (string) $r;
            if ($code === 0) { throw new BoMarketingError('API Marketing du BO injoignable (' . $url . ($err !== '' ? ' : ' . $err : '') . ')'); }
        }
        if ($code < 200 || $code >= 300) { throw new BoMarketingError('API Marketing du BO : GET ' . $path . ' → HTTP ' . $code); }
        $j = json_decode($b, true);
        if (!is_array($j)) { throw new BoMarketingError('API Marketing du BO : GET ' . $path . ' — réponse non JSON'); }
        return $j;
    }
}

/** L'erreur du dernier chargement des opérations (null : tout va bien). */
function socOperationsErreur(?string $e = null, bool $poser = false): ?string
{
    static $err = null;
    if ($poser) { $err = $e; }
    return $err;
}

/**
 * Les opérations du Marketing du BO, au format des écrans (`campaigns`).
 * Une boutique du Marketing (`mar_shop`, code `erp-N`) est la boutique N du module
 * (identifiant de caisse). Une campagne « réseau » sans boutique choisie vise tout le réseau.
 */
function socOperations(bool $forcer = false): array
{
    static $cache = null;
    if ($cache !== null && !$forcer) { return $cache; }
    socOperationsErreur(null, true);
    try {
        $liste = BoMarketing::get('campaigns');
        $mshops = [];
        foreach ((array) BoMarketing::get('shops') as $s) {
            if (preg_match('/^erp-(\d+)$/', (string) ($s['code'] ?? ''), $m)) { $mshops[(int) ($s['id'] ?? 0)] = $m[1]; }
        }
    } catch (BoMarketingError $e) {
        socOperationsErreur($e->getMessage(), true);
        return $cache = [];
    }
    $nos = array_map(static fn ($s) => (string) $s['id'], socReseau());
    $bo = rtrim((string) (socSetting('url_bo') ?? '/consulant_bo/'), '/') . '/';
    $out = [];
    foreach (array_slice(array_values(array_filter((array) $liste, 'is_array')), 0, 80) as $c) {
        $id = (int) ($c['id'] ?? 0);
        if ($id <= 0) { continue; }
        try { $d = BoMarketing::get('campaigns/' . $id); } catch (BoMarketingError $e) { $d = $c; }
        $out[] = socOperation($d + $c, $mshops, $nos, $bo);
    }
    return $cache = $out;
}

/** Une campagne du Marketing du BO → une opération des écrans. */
function socOperation(array $c, array $mshops, array $nos, string $bo): array
{
    $id = (int) $c['id'];
    $shops = []; $split = [];
    foreach ((array) ($c['shops'] ?? []) as $s) {
        $sid = $mshops[(int) ($s['shop_id'] ?? 0)] ?? null;
        if ($sid === null || !in_array($sid, $nos, true)) { continue; }
        $shops[] = $sid;
        if ((float) ($s['local_budget_amount'] ?? 0) > 0) { $split[$sid] = (float) $s['local_budget_amount']; }
    }
    if (!$shops && (string) ($c['scope'] ?? '') === 'RESEAU') { $shops = $nos; }
    $debut = socJour($c['starts_on'] ?? null); $fin = socJour($c['ends_on'] ?? null);
    $auj = date('Y-m-d');
    $statut = $debut === null || $debut > $auj ? 'à venir' : ($fin !== null && $fin < $auj ? 'terminée' : 'en cours');
    $offre = is_array($c['offer'] ?? null) ? $c['offer'] : [];
    $produits = array_values(array_filter(array_map(static fn ($i) => trim((string) ($i['label'] ?? '')), (array) ($offre['items'] ?? []))));
    $leviers = array_values(array_filter((array) ($c['levers'] ?? []), 'is_array'));
    $objectif = null;
    foreach ($leviers as $l) {
        if (isset($l['target_value']) && (float) $l['target_value'] > 0) {
            $objectif = ['metric' => (string) ($l['label'] ?? ''), 'value' => (float) $l['target_value'], 'unit' => (string) ($l['target_unit'] ?? '')];
            break;
        }
    }
    $img = (string) ($c['image_url'] ?? '');
    if ($img !== '' && !preg_match('#^(https?:)?//#', $img)) { $img = $bo . 'assistant/' . preg_replace('#^\./#', '', $img); }
    return [
        'id' => 'bo-' . $id, 'boId' => $id, 'source' => 'bo', 'name' => (string) ($c['name'] ?? ''),
        'type' => (string) ($c['type_code'] ?? ''), 'typeLabel' => (string) ($c['type_label'] ?? ''),
        'start' => $debut, 'end' => $fin, 'status' => $statut,
        'boStatus' => (string) ($c['status_label'] ?? ''), 'boStatusCode' => (string) ($c['status_code'] ?? ''),
        'budget' => (float) ($c['budget_amount'] ?? 0), 'spentBo' => (float) ($c['spent_amount'] ?? 0),
        'shops' => $shops, 'split' => $split, 'products' => $produits, 'goal' => $objectif,
        'levers' => array_map(static fn ($l) => (string) ($l['label'] ?? ''), $leviers),
        'channels' => array_values(array_map(static fn ($x) => is_array($x) ? (string) ($x['label'] ?? $x['name'] ?? '') : (string) $x, (array) ($c['channels'] ?? []))),
        'description' => trim((string) ($offre['title'] ?? '') . ((string) ($offre['mechanic_text'] ?? '') !== '' ? ' — ' . $offre['mechanic_text'] : '')),
        'image' => $img !== '' ? $img : null, 'scope' => (string) ($c['scope'] ?? ''), 'client' => (string) ($c['client_target'] ?? ''),
        'lien' => $bo . 'assistant/?id=' . $id,
    ];
}

/** Une opération par identifiant (`bo-12`). */
function socOperationParId(string $id): ?array
{
    foreach (socOperations() as $o) { if ($o['id'] === $id) { return $o; } }
    return null;
}

/** Les publications Meta rattachées : [objet (mfb-… / mig-…) => opération (bo-…)]. */
function socRattachements(): array
{
    $out = [];
    foreach (Db::rows('SELECT objet, operation_id FROM social_rattachement') as $r) { $out[(string) $r['objet']] = (string) $r['operation_id']; }
    return $out;
}

/**
 * Rattache (ou détache, `operation` null) un post ou une publicité à une opération du Marketing du BO.
 *  - post du module, publicité (du module ou récupérée chez Meta) : son champ `campaign` ;
 *  - publication lue en direct chez Meta (`mfb-…`, `mig-…`) : le lien seul, dans social_rattachement.
 */
function socRattacher(string $kind, string $id, ?string $operation): array
{
    if (!in_array($kind, ['post', 'ad'], true) || !preg_match('/^[\w.\-]{1,80}$/', $id)) { throw new HttpError(400, 'objet invalide'); }
    $op = null;
    if ($operation !== null && $operation !== '') {
        $op = socOperationParId($operation);
        if ($op === null) { throw new HttpError(404, 'opération inconnue du Marketing du BO : ' . $operation . (socOperationsErreur() ? ' (' . socOperationsErreur() . ')' : '')); }
    }
    $nom = '';
    if ($kind === 'post' && preg_match('/^m(fb|ig)-/', $id)) {
        if ($op === null) { Db::exec('DELETE FROM social_rattachement WHERE objet = ?', [$id]); }
        else {
            Db::exec('INSERT INTO social_rattachement (objet, operation_id, par, le) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE operation_id = VALUES(operation_id), par = VALUES(par), le = VALUES(le)',
                [$id, $op['id'], socUser(), date('Y-m-d H:i:s')]);
        }
        $nom = 'publication ' . $id;
    } else {
        $col = $kind === 'post' ? 'posts' : 'ads';
        $item = socItem($col, $id);
        if ($item === null) { throw new HttpError(404, ($kind === 'post' ? 'post' : 'publicité') . ' introuvable : ' . $id); }
        $item['campaign'] = $op['id'] ?? null;
        socItemPut($col, $item);
        $nom = '« ' . (string) ($item['title'] ?? $item['name'] ?? $id) . ' »';
    }
    socLog(socUser(), 'Opération', $op ? ucfirst($nom) . ' rattaché(e) à « ' . $op['name'] . ' »' : ucfirst($nom) . ' détaché(e) de son opération');
    return ['ok' => true, 'kind' => $kind, 'id' => $id, 'operation' => $op['id'] ?? null];
}
