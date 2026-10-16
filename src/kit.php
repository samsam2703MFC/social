<?php
declare(strict_types=1);

/**
 * Le kit du mois : les posts que le réseau demande à chaque boutique de publier ce mois-ci — un modèle de l'espace
 * franchisé, une date conseillée, un mot d'explication (`kit_mois`, réglage du module). La marque le compose dans
 * « Espace franchisé » et voit qui l'a programmé ; la boutique le retrouve dans « Créer un post » (« À publier ce
 * mois-ci »), prérempli au clic (modèle et date).
 *
 * Un élément du kit est « fait » pour une boutique dès qu'elle a programmé ou publié un post de ce modèle à moins de
 * sept jours de la date conseillée (ni refusé, ni brouillon).
 */

const SOC_KIT_MAX = 40;

function socKit(): array
{
    $l = socSetting('kit_mois');
    $l = is_array($l) ? array_values(array_filter($l, 'is_array')) : [];
    usort($l, static fn ($a, $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
    return $l;
}

/** Enregistre le kit (la liste entière) : modèle de l'espace franchisé existant, date valable, note courte. */
function socKitPut(array $items): array
{
    $modeles = [];
    foreach (sfModeles() as $m) { $modeles[(string) $m['id']] = (string) ($m['name'] ?? ''); }
    $out = []; $err = [];
    foreach (array_slice($items, 0, SOC_KIT_MAX) as $i => $it) {
        if (!is_array($it)) { continue; }
        $mod = (string) ($it['modele'] ?? ''); $date = (string) ($it['date'] ?? '');
        if (!isset($modeles[$mod])) { $err[] = 'Ligne ' . ($i + 1) . ' : modèle inconnu.'; continue; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) { $err[] = 'Ligne ' . ($i + 1) . ' : date invalide.'; continue; }
        $id = preg_match('/^k[\w-]{1,30}$/', (string) ($it['id'] ?? '')) ? (string) $it['id'] : 'k' . substr(sha1($mod . $date . $i . microtime()), 0, 10);
        $out[] = ['id' => $id, 'modele' => $mod, 'nom' => $modeles[$mod], 'date' => $date, 'mois' => substr($date, 0, 7), 'note' => mb_substr(trim((string) ($it['note'] ?? '')), 0, 200)];
    }
    if ($err) { throw new HttpError(422, implode(' ', $err)); }
    socSettingPut('kit_mois', $out, false);
    socLog(socUser(), 'Espace franchisé', 'Kit du mois enregistré : ' . count($out) . ' post(s)');
    return socKitVue();
}

/** Le kit est-il fait pour cette boutique ? (un post du modèle, programmé ou publié, à ±7 jours de la date conseillée) */
function socKitFait(array $item, string $shop, array $posts): ?array
{
    $du = date('Y-m-d', strtotime($item['date'] . ' -7 days')); $au = date('Y-m-d', strtotime($item['date'] . ' +7 days'));
    foreach ($posts as $p) {
        if (($p['origin'] ?? '') !== 'franchise' || (string) ($p['template'] ?? '') !== $item['modele']) { continue; }
        if (!in_array($shop, array_map('strval', (array) ($p['shops'] ?? [])), true) || !empty($p['refus']) || in_array($p['status'] ?? '', ['Brouillon', 'Refusé'], true)) { continue; }
        $j = (string) ($p['date'] ?? '');
        if ($j >= $du && $j <= $au) { return ['date' => $j, 'statut' => (string) ($p['status'] ?? '')]; }
    }
    return null;
}

/** La vue de la marque : le kit (du mois passé à dans deux mois) et, pour chaque élément, qui l'a fait. */
function socKitVue(): array
{
    $debut = date('Y-m', strtotime('first day of last month')); $fin = date('Y-m', strtotime('first day of +2 months'));
    $items = array_values(array_filter(socKit(), static fn ($i) => $i['mois'] >= $debut && $i['mois'] <= $fin));
    $shops = array_values(array_filter(socReseau(), static fn ($s) => empty($s['horsCaisse'])));
    $posts = socItems('posts');
    foreach ($items as &$it) {
        $it['boutiques'] = array_map(static function ($s) use ($it, $posts) {
            $f = socKitFait($it, (string) $s['id'], $posts);
            return ['id' => (string) $s['id'], 'nom' => (string) $s['name'], 'fait' => $f !== null, 'date' => $f['date'] ?? null];
        }, $shops);
        $it['faits'] = count(array_filter($it['boutiques'], static fn ($b) => $b['fait']));
    }
    unset($it);
    return ['items' => $items, 'tous' => socKit(), 'boutiques' => count($shops),
        'modeles' => array_map(static fn ($m) => ['id' => (string) $m['id'], 'nom' => (string) ($m['name'] ?? ''), 'actif' => !empty($m['active'])], sfModeles())];
}

/** « À publier ce mois-ci » pour une boutique : le kit du mois en cours et du suivant, fait ou à faire. */
function sfKit(string $shop): array
{
    $mois = date('Y-m'); $suivant = date('Y-m', strtotime('first day of next month'));
    $actifs = [];
    foreach (sfModeles(true) as $m) { $actifs[(string) $m['id']] = $m; }
    $posts = socItems('posts');
    $out = [];
    foreach (socKit() as $it) {
        if (!in_array($it['mois'], [$mois, $suivant], true) || !isset($actifs[$it['modele']])) { continue; }
        $f = socKitFait($it, $shop, $posts);
        $out[] = $it + ['fait' => $f !== null, 'faitLe' => $f['date'] ?? null, 'passe' => $it['date'] < date('Y-m-d')];
    }
    return ['items' => $out];
}
