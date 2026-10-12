<?php
/**
 * Points d'accroche de l'hôte — À COPIER vers config/hote.php (hors Git) le jour où
 * le module Social & Ads est transporté dans l'ERP (ou tout autre hôte).
 *
 * Sans config/hote.php, le module se comporte comme aujourd'hui, à côté du BO
 * consultant : base et session du BO, journal ceo_journal_entry, boutiques lues
 * dans l'API TFBuddy + le BO, franchisés connectés par compte atelierby ou code.
 *
 * Chaque entrée est facultative et ne remplace QU'UN point. Rien d'autre à modifier
 * dans le module : ses tables (social_*), son API et ses écrans restent identiques.
 * Détails : docs/INTEGRATION-ERP.md.
 */
return [
    // La base de données de l'ERP (les tables social_* y vivent).
    // 'pdo' => static fn (): PDO => Erp\Db::pdo(),

    // La session de l'utilisateur franchiseur (accès à /social et à son API).
    // 'auth' => static fn (): bool => Erp\Auth::check() && Erp\Auth::can('social.admin'),

    // Le nom affiché dans le journal pour les gestes faits depuis les écrans.
    // 'utilisateur' => static fn (): string => Erp\Auth::user()->fullName(),

    // Le journal de l'ERP (le module garde aussi le sien, social_activite).
    // 'journal' => static function (string $at, string $qui, string $quoi, string $detail): void {
    //     Erp\Journal::add($at, $qui, 'Social & Ads', $quoi . ' · ' . $detail);
    // },

    // Les boutiques : reçoit la liste calculée par le module (API TFBuddy + BO) et la rend,
    // complétée ou remplacée. Champs attendus : id, name, langs, address, hours, hoursNl,
    // page, ig, fb, igs, ads, ids{page_id, ad_account_id, instagram_user_id, pixel_id}, geo…
    // 'boutiques' => static fn (array $liste): array => $liste,

    // Le franchisé déjà connecté à l'ERP : ouvre directement son espace (/social_franchisee),
    // sans compte atelierby ni code d'accès. null = pas de franchisé connecté.
    // 'franchise_session' => static fn (): ?array => Erp\Auth::isOwner()
    //     ? ['shops' => Erp\Auth::user()->shopIds(), 'name' => Erp\Auth::user()->fullName()] : null,
];
