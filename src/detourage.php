<?php
declare(strict_types=1);

/**
 * Détourage d'une photo (fond retiré, PNG transparent) par l'API remove.bg.
 *
 * La clé (`removebg_api_key`, Paramètres → Rédaction IA & charte) reste sur le
 * serveur. Le navigateur envoie l'image du post : `data:` (photo déposée ou
 * rapatriée), photo du catalogue du webshop (hôte admis), ou fichier du module
 * (`img/…`, `uploads/…`). Le serveur rend le PNG détouré en `data:` ; rien n'est
 * gardé ici (il part avec le post quand celui-ci est enregistré).
 */

final class SocDetourage
{
    /** @var null|callable(array $corps): array{0:int,1:string,2:array} — remplacé par la recette */
    public static $transport = null;

    public static function cle(): string { return trim((string) (socSetting('removebg_api_key') ?? '')); }

    public static function configured(): bool { return self::cle() !== ''; }

    /** POST https://api.remove.bg/v1.0/removebg (JSON) → [code, corps, en-têtes en minuscules]. */
    public static function appel(array $corps): array
    {
        if (self::$transport) { return (self::$transport)($corps); }
        $h = [];
        $ch = curl_init('https://api.remove.bg/v1.0/removebg');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['X-Api-Key: ' . self::cle(), 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($corps),
            CURLOPT_HEADERFUNCTION => static function ($c, string $l) use (&$h): int { $p = explode(':', $l, 2); if (count($p) === 2) { $h[strtolower(trim($p[0]))] = trim($p[1]); } return strlen($l); }]);
        $r = curl_exec($ch);
        $code = $r === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $r === false ? curl_error($ch) : '';
        curl_close($ch);
        if ($code === 0) { throw new HttpError(502, 'remove.bg injoignable' . ($err !== '' ? ' : ' . $err : '')); }
        return [$code, (string) $r, $h];
    }
}

/** Les octets de l'image envoyée par l'écran (data:, catalogue du webshop, fichier du module). */
function socDetourageOctets(string $image): string
{
    if (preg_match('#^data:image/(png|jpe?g|webp);base64,#i', $image, $m)) {
        $o = base64_decode(substr($image, strlen($m[0])), true);
        if ($o === false || $o === '') { throw new HttpError(422, 'image illisible'); }
    } elseif (preg_match('#^https://#i', $image)) {
        $o = socMediaWebshop($image)->octets;  // seules les images du catalogue du webshop passent
    } elseif (preg_match('#^(img|uploads)/[\w./-]+\.(png|jpe?g|webp)$#i', $image) && !str_contains($image, '..')) {
        $f = ($image[0] === 'u' ? socUploadsDir() . '/' . substr($image, strlen('uploads/')) : __DIR__ . '/../public/' . $image);
        $o = is_file($f) ? (string) file_get_contents($f) : '';
        if ($o === '') { throw new HttpError(404, 'image introuvable : ' . $image); }
    } else {
        throw new HttpError(422, 'image refusée : une photo du post (déposée, du webshop ou de la médiathèque)');
    }
    if (strlen($o) > 12 * 1024 * 1024) { throw new HttpError(422, 'image trop lourde pour le détourage (12 Mo au plus)'); }
    if (socMediaType($o) === null) { throw new HttpError(422, 'ce fichier n’est pas une image'); }
    return $o;
}

/** Détoure une photo : [src => PNG en data:, credits => crédits remove.bg consommés]. */
function socDetourer(array $b): array
{
    if (!SocDetourage::configured()) { throw new HttpError(409, 'détourage non configuré : saisissez la clé remove.bg dans Paramètres → Rédaction IA & charte'); }
    $octets = socDetourageOctets(trim((string) ($b['image'] ?? '')));
    [$code, $corps, $h] = SocDetourage::appel(['image_file_b64' => base64_encode($octets), 'size' => 'auto', 'format' => 'png', 'type' => 'auto']);
    $j = json_decode($corps, true);
    if ($code === 200) {
        $png = base64_decode((string) ($j['data']['result_b64'] ?? ''), true);
        if ($png === false || $png === '' || socMediaType($png) !== 'image/png') { throw new HttpError(502, 'remove.bg n’a pas rendu d’image'); }
        $credits = $h['x-credits-charged'] ?? null;
        socLog(socUser(), 'Visuel', 'Photo détourée (remove.bg' . ($credits !== null ? ', ' . $credits . ' crédit(s)' : '') . ')');
        return ['src' => 'data:image/png;base64,' . base64_encode($png), 'credits' => $credits !== null ? (float) $credits : null];
    }
    $motif = (string) ($j['errors'][0]['title'] ?? ('HTTP ' . $code));
    throw match (true) {
        $code === 402 => new HttpError(409, 'crédits remove.bg épuisés (' . $motif . ')'),
        $code === 401 || $code === 403 => new HttpError(409, 'clé remove.bg refusée (' . $motif . ') : vérifiez-la dans Paramètres'),
        $code === 429 => new HttpError(429, 'remove.bg : trop de demandes, réessayez dans une minute'),
        $code === 400 => new HttpError(422, 'remove.bg n’a pas pu détourer cette photo : ' . $motif),
        default => new HttpError(502, 'remove.bg : ' . $motif),
    };
}
