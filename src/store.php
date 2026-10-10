<?php
declare(strict_types=1);

/**
 * L'état des écrans : lecture d'un bloc (GET /state) et enregistrement des
 * changements (POST /state).
 *
 * Les écrans manipulent des objets (posts, campagnes, pubs, formulaires,
 * groupes, modèles) ; le front n'envoie QUE ce qui a changé depuis sa lecture
 * — `upsert` et `delete` par collection — jamais la liste entière : deux
 * personnes qui travaillent en même temps ne s'effacent pas l'une l'autre.
 *
 * Le serveur reste maître de ce qui vient de Meta : le statut d'un post
 * publié, ses échecs, l'état d'une pub. Un écran ne peut pas déclarer
 * « Publié » ce que Meta n'a pas publié.
 */

const SOC_KINDS = ['posts', 'ads', 'campaigns', 'leadforms', 'groups', 'templates'];
/** Champs d'un post que seul le serveur écrit (ils viennent de Meta). */
const SOC_POST_SERVEUR = ['partial', 'error', 'failedShops', 'publishedAt'];

/** Une liste → texte « a,b,c » pour les colonnes de lecture SQL. */
function socCsv(mixed $v): ?string { return is_array($v) ? implode(',', array_map('strval', $v)) : null; }
function socJour(mixed $v): ?string { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : null; }
function socNum(mixed $v): ?float { return is_numeric($v) ? (float) $v : null; }
function socTxt(mixed $v, int $max = 0): ?string { if ($v === null || is_array($v)) { return null; } $v = (string) $v; return $max ? mb_substr($v, 0, $max) : $v; }

/**
 * Chaque collection de l'écran a SA table, avec ses colonnes lisibles en SQL.
 * kind → [table, colonnes(objet) → valeurs].
 */
function socTables(): array
{
    return [
        'posts' => ['social_content', static fn (array $p) => [
            'titre' => socTxt($p['title'] ?? null, 200), 'type' => socTxt($p['type'] ?? null, 60), 'statut' => socTxt($p['status'] ?? null, 30),
            'date_pub' => socJour($p['date'] ?? null), 'heure' => socTxt($p['time'] ?? null, 5), 'campagne_id' => socTxt($p['campaign'] ?? null, 64),
            'boutiques' => socCsv($p['shops'] ?? null), 'facebook' => !empty($p['fb']) ? 1 : 0, 'instagram' => !empty($p['ig']) ? 1 : 0,
            'texte_fr' => socTxt($p['text'] ?? null), 'texte_nl' => socTxt($p['textNl'] ?? null), 'lien' => socTxt($p['link'] ?? null, 500),
            'image' => is_string($p['img'] ?? null) && !str_starts_with($p['img'], 'data:') ? mb_substr($p['img'], 0, 500) : null,
            'auteur' => socTxt($p['author'] ?? null, 120), 'publie_le' => socTxt($p['publishedAt'] ?? null), 'erreur' => socTxt($p['error'] ?? null)]],
        'campaigns' => ['social_campagne', static fn (array $c) => [
            'nom' => socTxt($c['name'] ?? null, 200), 'type' => socTxt($c['type'] ?? null, 40), 'statut' => socTxt($c['status'] ?? null, 30),
            'debut' => socJour($c['start'] ?? null), 'fin' => socJour($c['end'] ?? null), 'budget' => socNum($c['budget'] ?? null),
            'objectif_kpi' => socTxt($c['goal']['metric'] ?? null, 80), 'objectif_valeur' => socNum($c['goal']['value'] ?? null),
            'boutiques' => socCsv($c['shops'] ?? null), 'produits' => is_array($c['products'] ?? null) ? implode(' | ', $c['products']) : null]],
        'ads' => ['social_publicite', static fn (array $a) => [
            'nom' => socTxt($a['name'] ?? null, 200), 'campagne_id' => socTxt($a['campaign'] ?? null, 64), 'objectif' => socTxt($a['objective'] ?? null, 60),
            'statut' => socTxt($a['status'] ?? null, 30), 'portee' => socTxt($a['scope'] ?? null, 20), 'type_budget' => socTxt($a['budgetType'] ?? null, 20),
            'budget' => socNum($a['budget'] ?? null), 'plafond' => socNum($a['cap'] ?? null), 'depense' => socNum($a['spent'] ?? null),
            'debut' => socJour($a['start'] ?? null), 'fin' => socJour($a['end'] ?? null), 'content_id' => socTxt($a['post'] ?? null, 64),
            'formulaire_id' => socTxt($a['leadForm'] ?? null, 64), 'motif' => socTxt($a['reason'] ?? null)]],
        'leadforms' => ['social_formulaire', static fn (array $f) => [
            'famille' => socTxt($f['family'] ?? null, 64), 'version' => isset($f['version']) ? (int) $f['version'] : null, 'nom' => socTxt($f['name'] ?? null, 200),
            'locale' => socTxt($f['locale'] ?? null, 10), 'statut' => socTxt($f['status'] ?? null, 30), 'cree_le' => socJour($f['created'] ?? null),
            'boutiques' => socCsv($f['shops'] ?? null), 'erreur_meta' => socTxt($f['metaError'] ?? null)]],
        'groups' => ['social_groupe', static fn (array $g) => [
            'nom' => socTxt($g['name'] ?? null, 160), 'langue' => socTxt($g['lang'] ?? null, 10), 'boutiques' => socCsv($g['shops'] ?? null)]],
        'templates' => ['social_modele', static fn (array $t) => [
            'nom' => socTxt($t['name'] ?? null, 160), 'type' => socTxt($t['type'] ?? null, 60), 'texte_fr' => socTxt($t['text'] ?? null), 'texte_nl' => socTxt($t['textNl'] ?? null)]],
    ];
}

function socTable(string $kind): array
{
    $t = socTables()[$kind] ?? null;
    if ($t === null) { throw new InvalidArgumentException('collection inconnue : ' . $kind); }
    return $t;
}

function socItems(string $kind): array
{
    [$table] = socTable($kind);
    $ordre = match ($kind) { 'posts' => 'date_pub, heure, id', 'campaigns' => 'debut, id', 'ads' => 'debut, id', 'leadforms' => 'famille, version, id', default => 'id' };
    $out = [];
    foreach (Db::rows("SELECT data FROM $table ORDER BY $ordre") as $r) {
        $d = json_decode((string) $r['data'], true);
        if (is_array($d)) { $out[] = $d; }
    }
    return $out;
}

function socItem(string $kind, string $id): ?array
{
    [$table] = socTable($kind);
    $r = Db::row("SELECT data FROM $table WHERE id = ?", [$id]);
    $d = $r ? json_decode((string) $r['data'], true) : null;
    return is_array($d) ? $d : null;
}

function socItemPut(string $kind, array $item): void
{
    [$table, $cols] = socTable($kind);
    $v = ['id' => (string) $item['id']] + $cols($item) + ['data' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'maj_le' => date('Y-m-d H:i:s')];
    $noms = array_keys($v);
    $sql = "INSERT INTO $table (" . implode(', ', $noms) . ') VALUES (' . implode(',', array_fill(0, count($noms), '?')) . ') ON DUPLICATE KEY UPDATE '
        . implode(', ', array_map(static fn ($n) => "$n = VALUES($n)", array_slice($noms, 1)));
    Db::exec($sql, array_values($v));
}

function socItemDelete(string $kind, string $id): void
{
    [$table] = socTable($kind);
    Db::exec("DELETE FROM $table WHERE id = ?", [$id]);
}

function socDefaults(): array
{
    $j = json_decode((string) @file_get_contents(__DIR__ . '/data/defaults.json'), true);
    return is_array($j) ? $j : [];
}

/**
 * Premier lancement : les modèles de posts livrés, et un groupe de boutiques
 * par zone du cockpit (`ceo_shop.zone`) — l'organisation que le réseau a déjà.
 */
function socSeed(): void
{
    if (socSetting('donnees_initiales') !== null) { return; }
    $d = socDefaults();
    foreach ((array) ($d['templates'] ?? []) as $t) { socItemPut('templates', $t); }
    $zones = [];
    foreach (socShops() as $s) { if ($s['zone'] !== '') { $zones[$s['zone']][] = $s['id']; } }
    $i = 0;
    // Une zone d'une seule boutique n'est pas un groupe (en production, les zones portent souvent le nom du magasin).
    foreach ($zones as $z => $ids) { if (count($ids) > 1) { socItemPut('groups', ['id' => 'g' . (++$i), 'name' => $z, 'shops' => $ids]); } }
    socSettingPut('donnees_initiales', date('Y-m-d H:i:s'));
}

/* --- médias --------------------------------------------------------------------- */

/**
 * Les images et vidéos arrivent du navigateur en `data:` (visuel composé du
 * studio, photos déposées). Meta les télécharge par URL : on les écrit sous
 * public/uploads/ et l'objet garde le chemin. Parcours récursif.
 */
function socExtractMedia(mixed $v): mixed
{
    if (is_array($v)) {
        foreach ($v as $k => $x) { $v[$k] = socExtractMedia($x); }
        return $v;
    }
    if (!is_string($v) || strlen($v) < 200 || !preg_match('#^data:(image|video)/([a-z0-9.+-]+);base64,#i', $v, $m)) { return $v; }
    $ext = match (strtolower($m[2])) { 'jpeg', 'jpg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif', 'mp4' => 'mp4', 'quicktime' => 'mov', 'webm' => 'webm', default => null };
    if ($ext === null) { return $v; }
    $bin = base64_decode(substr($v, strlen($m[0])), true);
    if ($bin === false || $bin === '') { return $v; }
    $dir = socUploadsDir() . '/' . date('Ym');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return $v; }
    $nom = substr(hash('sha256', $bin), 0, 32) . '.' . $ext;
    if (!is_file($dir . '/' . $nom)) { @file_put_contents($dir . '/' . $nom, $bin); }
    return is_file($dir . '/' . $nom) ? 'uploads/' . date('Ym') . '/' . $nom : $v;
}

function socUploadsDir(): string
{
    return (string) (getenv('SOCIAL_UPLOADS_DIR') ?: __DIR__ . '/../public/uploads');
}

/* --- lecture --------------------------------------------------------------------- */

/** Tout ce que les écrans lisent, en un appel. */
function socStateGet(): array
{
    socSeed();
    $shops = socShops();
    $ids = []; $geo = [];
    foreach ($shops as $s) { $ids[$s['id']] = $s['ids']; if ($s['geo']) { $geo[$s['id']] = $s['geo']; } }
    $checked = array_filter(array_column($shops, 'checkedAt'));
    $d = socDefaults();
    return [
        'mode' => 'live', 'version' => SOCIAL_VERSION, 'today' => date('Y-m-d'), 'now' => date('Y-m-d H:i'),
        'user' => ['name' => socUser(), 'role' => 'Franchiseur', 'shops' => count($shops)],
        'boUrl' => (string) (socSetting('url_bo') ?? '/consulant_bo/'),
        'metaStatus' => Meta::statut(),
        'shops' => $shops,
        'posts' => socItems('posts'), 'ads' => socItems('ads'), 'campaigns' => socItems('campaigns'),
        'leadforms' => socItems('leadforms'), 'groups' => socItems('groups'), 'templates' => socItems('templates'),
        'leads' => socLeads(),
        'activity' => array_map(static fn ($r) => ['at' => substr((string) $r['at'], 0, 16), 'who' => $r['who'], 'what' => $r['what'], 'detail' => $r['detail']],
            Db::rows('SELECT le AS at, qui AS who, quoi AS what, detail FROM social_activite ORDER BY le DESC, id DESC LIMIT 400')),
        'meta' => ['version' => Meta::config()['version'], 'dsa' => (array) (socSetting('meta_dsa') ?? ($d['dsa'] ?? [])),
            'networkAccount' => (string) (socSetting('meta_compte_reseau') ?? ''), 'ids' => $ids, 'geo' => $geo,
            'lastCheck' => $checked ? max($checked) : null],
        'results' => socResults(),
        'catalogue' => socCatalogue(),
        'margin' => (float) (socSetting('marge') ?? ($d['margin'] ?? 0.62)),
        'params' => socParams(),
    ];
}

/* --- écriture --------------------------------------------------------------------- */

/**
 * POST /state — { changes: {kind: {upsert: [...], delete: [ids]}}, shops: [...], meta: {...}, activity: [...] }
 * Rend les objets tels que le serveur les a gardés (statuts Meta compris).
 */
function socStateSave(array $b): array
{
    $out = ['ok' => true, 'items' => [], 'deleted' => [], 'errors' => []];
    $who = socUser();

    foreach ((array) ($b['activity'] ?? []) as $a) {
        if (!is_array($a)) { continue; }
        $qui = (string) ($a['who'] ?? $who);
        socLog($qui !== '' ? $qui : $who, (string) ($a['what'] ?? 'Action'), (string) ($a['detail'] ?? ''));
    }

    if (isset($b['meta']) && is_array($b['meta'])) {
        $m = $b['meta'];
        if (isset($m['version']) && preg_match('/^v\d{2}\.\d$/', trim((string) $m['version']))) { socSettingPut('meta_version', trim((string) $m['version'])); }
        if (isset($m['networkAccount'])) { socSettingPut('meta_compte_reseau', preg_replace('/[^\w]/', '', trim((string) $m['networkAccount']))); }
        if (isset($m['dsa']) && is_array($m['dsa'])) { socSettingPut('meta_dsa', ['beneficiary' => (string) ($m['dsa']['beneficiary'] ?? ''), 'payor' => (string) ($m['dsa']['payor'] ?? '')]); }
        if (isset($m['ids']) && is_array($m['ids'])) {
            $shops = []; foreach (socShops() as $s) { $shops[$s['id']] = $s; }
            foreach ($m['ids'] as $sid => $ids) {
                if (!isset($shops[$sid]) || !is_array($ids)) { continue; }
                $n = $shops[$sid]['ids'];
                foreach (SOC_IDS as $k) { if (array_key_exists($k, $ids)) { $n[$k] = preg_replace('/[^\w]/', '', trim((string) $ids[$k])); } }
                if ($n !== $shops[$sid]['ids']) { socShopPut((string) $sid, ['ids' => $n]); }
            }
        }
    }

    if (isset($b['shops']) && is_array($b['shops'])) {
        foreach ($b['shops'] as $s) {
            if (!is_array($s) || empty($s['id'])) { continue; }
            $patch = array_intersect_key($s, array_flip(SOC_SHOP_CHAMPS));
            if (isset($patch['langs'])) { $patch['langs'] = array_values(array_filter((array) $patch['langs'], static fn ($l) => in_array($l, ['fr', 'nl'], true))) ?: ['fr']; }
            unset($patch['name']);  // le nom vient du cockpit
            if ($patch) { socShopPut((string) $s['id'], $patch); }
        }
    }

    foreach ((array) ($b['changes'] ?? []) as $kind => $ch) {
        if (!in_array($kind, SOC_KINDS, true) || !is_array($ch)) { continue; }
        foreach ((array) ($ch['delete'] ?? []) as $id) {
            $id = (string) $id;
            if ($kind === 'posts') { socUnqueue($id); }
            socItemDelete($kind, $id);
            $out['deleted'][] = [$kind, $id];
        }
        foreach ((array) ($ch['upsert'] ?? []) as $item) {
            if (!is_array($item) || !isset($item['id']) || !preg_match('/^[\w.\-]{1,64}$/', (string) $item['id'])) { continue; }
            $item = socExtractMedia($item);
            $avant = socItem($kind, (string) $item['id']);
            try {
                $item = match ($kind) {
                    'posts' => socPostHook($avant, $item),
                    'ads' => socAdHook($avant, $item),
                    'leadforms' => socLeadformHook($avant, $item),
                    default => $item,
                };
            } catch (Throwable $e) {
                $out['errors'][] = ['kind' => $kind, 'id' => $item['id'], 'error' => $e->getMessage()];
            }
            socItemPut($kind, $item);
            $out['items'][] = [$kind, socItem($kind, (string) $item['id'])];
        }
    }
    return $out;
}

/**
 * Un post enregistré. Le statut demandé par l'écran décide de la file :
 * « Programmé » → en file à l'heure dite ; « Publié » (Publier maintenant)
 * → publié dans la requête ; brouillon / à valider → retiré de la file.
 * Un post déjà publié garde ce que Meta en a dit.
 */
function socPostHook(?array $avant, array $p): array
{
    $sAvant = (string) ($avant['status'] ?? '');
    $s = (string) ($p['status'] ?? 'Brouillon');
    $publieAvant = in_array($sAvant, ['Publié', 'Échec'], true);
    if ($publieAvant && in_array($s, ['Publié', 'Échec'], true)) {
        // Le serveur garde la main sur le résultat de la publication.
        $p['status'] = $sAvant;
        foreach (SOC_POST_SERVEUR as $k) { $p[$k] = $avant[$k] ?? null; }
        return $p;
    }
    foreach (SOC_POST_SERVEUR as $k) { $p[$k] = null; }
    if ($s === 'Programmé') {
        socItemPut('posts', $p);
        socQueue($p);
        return $p;
    }
    if ($s === 'Publié') {
        // « Publier maintenant » : en file, échu tout de suite, publié ici.
        $p['status'] = 'Programmé';
        socItemPut('posts', $p);
        socQueue($p, true);
        socRunQueue((string) $p['id']);
        return socItem('posts', (string) $p['id']) ?? $p;
    }
    socUnqueue((string) $p['id']);
    return $p;
}
