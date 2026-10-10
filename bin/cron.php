<?php
declare(strict_types=1);

/**
 * Le cron du module (posé par bin/deploy.sh dans /etc/cron.d/social) :
 *
 *   * * * * *   php bin/cron.php queue      la file de publication (posts échus)
 *   7 * * * *   php bin/cron.php sync       pubs (statut, dépense), leads, résultats
 *   40 6 * * *  php bin/cron.php conn       contrôle des connexions Meta
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
        'sync'  => ['ads' => socAdsSync(), 'leads' => socLeadsSync(), 'results' => socResultsSync()],
        'conn'  => ['boutiques' => count(socTestConnexions())],
        'audit' => socAuditRun(),
        default => throw new InvalidArgumentException('tâche inconnue : ' . $tache . ' (queue | sync | conn | audit)'),
    };
    if ($tache !== 'queue' || $r['publies'] > 0) {
        echo date('Y-m-d H:i:s') . ' ' . $tache . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ' . $tache . ' ERREUR ' . $e->getMessage() . "\n");
    exit(1);
}
