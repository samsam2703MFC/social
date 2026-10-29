<?php
declare(strict_types=1);

/**
 * Écran « Formulaires de leads » — `POST /{page_id}/leadgen_forms`.
 *
 * Un formulaire ne se modifie pas chez Meta après création : une « nouvelle
 * version » de l'écran est un NOUVEAU formulaire sur chaque Page, l'ancienne
 * version étant archivée (`POST /{form_id}` status=ARCHIVED). Les pubs qui
 * pointaient l'ancienne reçoivent une nouvelle créa (src/ads.php).
 *
 * Récupération : le cron relit `GET /{form_id}/leads` (rattrapage) ; le
 * webhook `leadgen` (POST /api/v1/social/webhook) les reçoit en temps réel si
 * l'app Meta l'envoie ici.
 */

/** Les ids Meta d'un formulaire de l'écran : shop_id → form_id. */
function socLeadformMetaIds(string $localId): array
{
    $out = [];
    foreach (socMetaObjects('leadform', $localId) as $o) {
        if ($o['form_id']) { $out[(string) $o['shop_id']] = (string) $o['form_id']; }
    }
    return $out;
}

/** Le corps Meta d'un formulaire de l'écran. */
function socLeadformBody(array $f): array
{
    $questions = [];
    foreach ((array) ($f['questions'] ?? []) as $q) {
        if (($q['type'] ?? '') === 'CUSTOM') {
            // Sans option, une question à réponse libre : Meta refuse un `options` vide, il ne doit pas être envoyé.
            // Clés en a-z, 0-9 et « _ », uniques dans la question (deux options « 8 à 15 » et « 8-15 » ne se confondent pas).
            $cle = static fn (string $v, string $repli) => trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(socNorm($v))), '_') ?: $repli;
            $x = ['type' => 'CUSTOM', 'key' => $cle((string) ($q['key'] ?? ''), 'question_' . (count($questions) + 1)), 'label' => trim((string) ($q['label'] ?? ''))];
            $vues = [];
            foreach ((array) ($q['options'] ?? []) as $k => $o) {
                $v = trim((string) ($o['value'] ?? ''));
                if ($v === '') { continue; }
                $ok = $cle((string) (($o['key'] ?? '') ?: $v), 'option_' . ($k + 1));
                while (isset($vues[$ok])) { $ok .= '_' . ($k + 1); }
                $vues[$ok] = true;
                $x['options'][] = ['key' => $ok, 'value' => $v];
            }
            $questions[] = $x;
        } elseif (!empty($q['type'])) {
            $questions[] = ['type' => (string) $q['type']];
        }
    }
    $b = ['name' => (string) $f['name'] . ' · v' . (int) ($f['version'] ?? 1), 'locale' => strtoupper((string) ($f['locale'] ?? 'fr_FR')),
        'questions' => $questions,
        'privacy_policy' => ['url' => (string) ($f['privacy']['url'] ?? ''), 'link_text' => (string) ($f['privacy']['link_text'] ?? 'Politique de confidentialité')],
        'is_optimized_for_quality' => !empty($f['quality'])];
    if (!empty($f['followUp'])) { $b['follow_up_action_url'] = (string) $f['followUp']; }
    if (!empty($f['context']['title'])) {
        $b['context_card'] = ['title' => (string) $f['context']['title'], 'style' => (string) ($f['context']['style'] ?? 'PARAGRAPH_STYLE'),
            'content' => array_values(array_map('strval', (array) ($f['context']['content'] ?? []))), 'button_text' => (string) ($f['context']['button_text'] ?? '')];
    }
    if (!empty($f['thanks']['title'])) {
        $t = $f['thanks'];
        $b['thank_you_page'] = array_filter(['title' => (string) $t['title'], 'body' => (string) ($t['body'] ?? ''), 'button_type' => (string) ($t['button_type'] ?? 'VIEW_WEBSITE'),
            'button_text' => (string) ($t['button_text'] ?? ''), 'website_url' => (string) ($t['website_url'] ?? '')], static fn ($v) => $v !== '');
    }
    return $b;
}

/**
 * Un formulaire enregistré. Nouveau et actif → publié sur la Page de chaque
 * boutique. Passé à « Archivé » → archivé chez Meta.
 */
function socLeadformHook(?array $avant, array $f): array
{
    $s = (string) ($f['status'] ?? ''); $sAvant = (string) ($avant['status'] ?? '');
    $deja = socLeadformMetaIds((string) $f['id']);
    if ($s === 'Archivé' && $sAvant !== 'Archivé') {
        foreach ($deja as $fid) {
            try { Meta::call('POST', $fid, ['status' => 'ARCHIVED'], socPageTokenDuForm((string) $f['id'], $fid)); } catch (MetaError $e) { /* archivé côté écran quoi qu'il arrive */ }
        }
        return $f;
    }
    if ($s !== 'Actif') { return $f; }
    $shops = []; foreach (socShops() as $sh) { $shops[$sh['id']] = $sh; }
    $erreurs = []; $n = 0;
    foreach ((array) ($f['shops'] ?? []) as $sid) {
        if (isset($deja[$sid]) || !isset($shops[$sid])) { continue; }
        $shop = $shops[$sid];
        $o = ['kind' => 'leadform', 'local_id' => (string) $f['id'], 'shop_id' => $sid];
        try {
            if (!Meta::configured()) { throw new MetaError('token Meta absent', 190); }
            $pid = (string) $shop['ids']['page_id'];
            if ($pid === '') { throw new MetaError('page_id non renseigné', 100); }
            $r = Meta::call('POST', $pid . '/leadgen_forms', socLeadformBody($f), Meta::pageToken($pid));
            $o['form_id'] = (string) ($r['id'] ?? '');
            $o['meta_status'] = 'ACTIVE';
            $n++;
        } catch (MetaError $e) {
            $o['error'] = mb_substr($e->getMessage(), 0, 500);
            $erreurs[] = $shop['name'] . ' : ' . $e->getMessage();
        }
        socMetaObjectAdd($o);
    }
    $f['metaError'] = $erreurs ? 'Non publié chez Meta — ' . implode(' · ', $erreurs) : null;
    if ($erreurs) { socLog('Système', 'Échec', 'Formulaire « ' . ($f['name'] ?? '') . ' » : ' . implode(' · ', $erreurs)); }
    elseif ($n) { socLog('Système', 'Formulaire', '« ' . ($f['name'] ?? '') . ' » v' . (int) ($f['version'] ?? 1) . ' publié sur ' . $n . ' Page(s)'); }
    return $f;
}

function socPageTokenDuForm(string $localId, string $formId): ?string
{
    $r = Db::row('SELECT shop_id FROM social_meta_objet WHERE kind = ? AND local_id = ? AND form_id = ?', ['leadform', $localId, $formId]);
    $shop = $r ? socShop((string) $r['shop_id']) : null;
    try { return $shop && $shop['ids']['page_id'] !== '' ? Meta::pageToken((string) $shop['ids']['page_id']) : null; }
    catch (MetaError $e) { return null; }
}

/** `full_name` → `FULL_NAME` ; les clés personnalisées restent telles quelles. */
function socLeadKey(string $name): string
{
    $std = ['full_name' => 'FULL_NAME', 'first_name' => 'FIRST_NAME', 'last_name' => 'LAST_NAME', 'email' => 'EMAIL',
        'phone_number' => 'PHONE', 'phone' => 'PHONE', 'city' => 'CITY', 'zip_code' => 'ZIP', 'post_code' => 'ZIP', 'zip' => 'ZIP',
        'company_name' => 'COMPANY_NAME', 'job_title' => 'JOB_TITLE'];
    return $std[strtolower($name)] ?? $name;
}

/** Enregistre un lead tel que Meta le rend. Idempotent (l'id Meta est la clé). */
function socLeadSave(array $l, ?string $formLocal, ?string $shopId): bool
{
    if (empty($l['id'])) { return false; }
    $fd = [];
    foreach ((array) ($l['field_data'] ?? []) as $f) { $fd[socLeadKey((string) ($f['name'] ?? ''))] = implode(', ', (array) ($f['values'] ?? [])); }
    $t = strtotime((string) ($l['created_time'] ?? '')) ?: time();
    return Db::exec('INSERT IGNORE INTO social_lead (id, formulaire_id, form_meta, shop_id, pub, cree_le, champs) VALUES (?,?,?,?,?,?,?)',
        [(string) $l['id'], $formLocal, (string) ($l['form_id'] ?? ''), $shopId, (string) ($l['ad_name'] ?? $l['ad_id'] ?? ''),
         date('Y-m-d H:i:s', $t), json_encode($fd, JSON_UNESCAPED_UNICODE)]) > 0;
}

/** Les leads, dans la forme de l'écran. */
function socLeads(): array
{
    return array_map(static fn ($r) => ['id' => $r['id'], 'form' => $r['formulaire_id'], 'created_time' => substr((string) $r['cree_le'], 0, 16),
        'ad' => (string) $r['pub'], 'shop' => $r['shop_id'], 'field_data' => json_decode((string) $r['champs'], true) ?: []],
        Db::rows('SELECT * FROM social_lead ORDER BY cree_le DESC LIMIT 2000'));
}

/** Rattrapage : relit les leads de chaque formulaire publié. */
function socLeadsSync(): int
{
    if (!Meta::configured()) { return 0; }
    $n = 0;
    foreach (Db::rows("SELECT * FROM social_meta_objet WHERE kind = 'leadform' AND form_id IS NOT NULL") as $o) {
        $tok = socPageTokenDuForm((string) $o['local_id'], (string) $o['form_id']);
        $path = (string) $o['form_id'] . '/leads';
        $params = ['fields' => 'id,created_time,field_data,ad_id,ad_name,form_id', 'limit' => 100];
        for ($p = 0; $p < 20; $p++) {
            try { $r = Meta::call('GET', $path, $params, $tok); } catch (MetaError $e) { break; }
            foreach ((array) ($r['data'] ?? []) as $l) { if (socLeadSave($l, (string) $o['local_id'], (string) $o['shop_id'])) { $n++; } }
            $next = $r['paging']['next'] ?? null;
            if (!is_string($next) || $next === '') { break; }
            $path = $next; $params = [];
        }
    }
    return $n;
}

/** GET /webhook — la vérification d'abonnement de Meta (hub.challenge). */
function socWebhookVerif(): string
{
    $tok = Meta::config()['verifyToken'];
    if ($tok === '' || ($_GET['hub_verify_token'] ?? '') !== $tok || ($_GET['hub_mode'] ?? '') !== 'subscribe') {
        throw new HttpError(403, 'jeton de vérification invalide');
    }
    return (string) ($_GET['hub_challenge'] ?? '');
}

/** POST /webhook — événements `leadgen`, signés par la clé secrète de l'app. */
function socWebhook(string $corps, ?string $signature): array
{
    $secret = Meta::config()['appSecret'];
    if ($secret === '' || !is_string($signature) || !hash_equals('sha256=' . hash_hmac('sha256', $corps, $secret), $signature)) {
        throw new HttpError(401, 'signature invalide');
    }
    $p = json_decode($corps, true);
    $n = 0;
    foreach ((array) ($p['entry'] ?? []) as $e) {
        foreach ((array) ($e['changes'] ?? []) as $c) {
            if (($c['field'] ?? '') !== 'leadgen') { continue; }
            $v = (array) ($c['value'] ?? []);
            $o = Db::row("SELECT * FROM social_meta_objet WHERE kind = 'leadform' AND form_id = ?", [(string) ($v['form_id'] ?? '')]);
            // Pas un de NOS formulaires (ex. une candidature du CRM, dans le même
            // envoi relayé par l'API franchise — application Meta partagée) : ce
            // lead ne nous regarde pas. Comme socLeadsSync, qui ne lit que les nôtres.
            if ($o === null) { continue; }
            $tok = socPageTokenDuForm((string) $o['local_id'], (string) $o['form_id']);
            try {
                $l = Meta::call('GET', (string) $v['leadgen_id'], ['fields' => 'id,created_time,field_data,ad_id,ad_name,form_id'], $tok);
                if (socLeadSave($l, $o['local_id'] ?? null, $o['shop_id'] ?? null)) { $n++; }
            } catch (MetaError $e2) { /* le cron rattrapera */ }
        }
    }
    return ['ok' => true, 'leads' => $n];
}
