<?php
declare(strict_types=1);

/**
 * Le cron du module (posé par bin/deploy.sh dans /etc/cron.d/social) :
 *
 *   * * * * *   php bin/cron.php queue      la file de publication (posts échus)
 *   7 * * * *   php bin/cron.php sync       pubs (statut, dépense, dark posts), leads, résultats, grille Instagram, commentaires des dark posts
 *   40 6 * * *  php bin/cron.php conn       contrôle des connexions Meta
 *   37 * * * *  php bin/cron.php ids        identifiants Meta par boutique relus dans le portefeuille (selon la fréquence choisie)
 *   20 5 * * *  php bin/cron.php audit      audit des routes de l'API TFBuddy
 *
 * Un verrou par tâche : une exécution longue n'en chevauche pas une autre.
 */
require __DIR__ . '/../src/api.php';

$tache = $argv[1] ?? 'queue';
$verrou = fopen(sys_get_temp_dir() . '/social-cron-' . preg_replace('/\W/', '', $tache) . '.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) { exit(0); }

try {
    socInstall();
    $r = match ($tache) {
        'queue' => ['publies' => count(socRunQueue())],
        'sync'  => ['campagnes' => Meta::configured() ? socCampagnesMetaImporter(false)['total'] : 0, 'ads' => socAdsSync(), 'leads' => socLeadsSync(), 'results' => socResultsSync(), 'instagram' => socIgMediaSync(), 'commentaires' => socDarkCommentsSync()],
        'conn'  => ['boutiques' => count(socTestConnexions())],
        'ids'   => socIdsAuto(),
        'audit' => socAuditRun(),
        default => throw new InvalidArgumentException('tâche inconnue : ' . $tache . ' (queue | sync | conn | ids | audit)'),
    };
    if ($tache !== 'queue' || $r['publies'] > 0) {
        echo date('Y-m-d H:i:s') . ' ' . $tache . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ' . $tache . ' ERREUR ' . $e->getMessage() . "\n");
    exit(1);
}
