<?php
/**
 * Webcam — fonctions partagées.
 *
 * Les images sont poussées par FTP par la caméra dans :
 *   <SNAP_ROOT>/AAAA/MM/JJ/<CAM_PREFIX>_<canal>_AAAAMMJJhhmmss.jpg
 *
 * Voir doc/TECHNIQUE.md pour les contrats et contraintes.
 *
 * Copyright (C) 2026 Stephanfo
 *
 * Ce programme est un logiciel libre : vous pouvez le redistribuer et/ou le
 * modifier selon les termes de la GNU Affero General Public License version 3,
 * telle que publiée par la Free Software Foundation. Distribué sans aucune
 * garantie — voir le fichier LICENSE.
 */

declare(strict_types=1);

// Configuration : config.php (local, non versionné) sinon config.example.php.
// Le fallback permet de faire tourner le projet sans étape d'installation.
if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
} elseif (is_file(__DIR__ . '/config.example.php')) {
    require __DIR__ . '/config.example.php';
} else {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Configuration absente : copier config.example.php en config.php.\n");
}

/**
 * Contrat de configuration : constante => forme attendue.
 *
 * Un `config.php` écrit pour une version antérieure peut manquer une clé
 * ajoutée depuis ; une valeur peut aussi être présente mais inutilisable.
 * Les deux cas se manifestaient tard et mal — fatale « Undefined constant »
 * au milieu d'une requête, donc page blanche en production, ou pire : un
 * silence. Un fuseau mal orthographié fait retomber PHP sur UTC et décale
 * toutes les heures affichées sans rien signaler.
 *
 * On contrôle donc présence ET forme, avant toute utilisation. Trois règles
 * ne sont pas cosmétiques :
 *
 *   'prefix' — CAM_PREFIX est le seul fragment de config qui atteigne un
 *   nom de fichier et un en-tête HTTP. Le `.htaccess` ne sert que les noms
 *   `[A-Za-z0-9_-]+_<canal>_<horodatage>.jpg` : un préfixe hors de ce jeu
 *   produirait une liste JSON dont Apache refuse chaque image (403), panne
 *   invisible en dev où le `.htaccess` n'est pas lu.
 *
 *   'color' — THEME_COLOR et THEME_ACCENT sont injectés dans un bloc
 *   <style> d'index.php. `htmlspecialchars` n'y protège de rien : <style>
 *   est un élément à texte brut, les entités n'y sont jamais décodées.
 *   C'est cette contrainte de forme, et elle seule, qui empêche l'injection
 *   de règles CSS.
 *
 *   'timezone' — vérifié contre la base IANA plutôt qu'en testant le retour
 *   de `date_default_timezone_set`, pour nommer la constante fautive.
 *
 * Voir doc/DEPLOIEMENT.md §5.
 */
const CONFIG_SPEC = [
    'CAM_PREFIX'           => 'prefix',
    'SITE_TIMEZONE'        => 'timezone',
    'SITE_TITLE'           => 'string',
    'SITE_HEADING'         => 'string',
    'SITE_LOGO_ALT'        => 'string',
    'SITE_FOOTER'          => 'string',
    'SITE_DESCRIPTION'     => 'string',
    'SITE_BASE_URL'        => 'string',
    'SITE_OG_DESCRIPTION'  => 'string',
    'SITE_OG_IMAGE'        => 'string',
    'THEME_COLOR'          => 'color',
    'THEME_ACCENT'         => 'color',
    'CORS_ALLOWED_ORIGINS' => 'origins',
    'WINDOW_SECONDS'       => 'positive-int',
    'RETENTION_SECONDS'    => 'positive-int',
    'MAX_IMAGES'           => 'positive-int',
    'REFRESH_MS'           => 'positive-int',
    'LIST_MS'              => 'positive-int',
    'BASE_FPS'             => 'positive-int',
    'WARN_STALE_S'         => 'positive-int',
];

/**
 * Forme attendue, en clair, pour le message d'erreur.
 *
 * Ne jamais y reprendre la valeur reçue : ce message s'affiche sur des
 * endpoints publics. Nommer la constante et la forme suffit à diagnostiquer
 * (les noms sont de toute façon publics, ils sont dans config.example.php)
 * et n'expose pas le contenu de la configuration.
 */
const CONFIG_EXPECTED = [
    'string'       => 'chaîne de caractères attendue',
    'prefix'       => 'lettres, chiffres, tiret et underscore uniquement',
    'timezone'     => "identifiant de fuseau IANA attendu (ex. 'Europe/Paris')",
    'color'        => "couleur hexadécimale attendue (ex. '#045BCB')",
    'origins'      => 'tableau de chaînes attendu',
    'positive-int' => 'entier strictement positif attendu',
];

$configErrors = [];
foreach (CONFIG_SPEC as $name => $rule) {
    if (!defined($name)) {
        $configErrors[] = "$name : constante absente";
        continue;
    }
    $v = constant($name);
    $ok = match ($rule) {
        'string'   => is_string($v),
        'prefix'   => is_string($v) && preg_match('/^[A-Za-z0-9_-]+$/', $v) === 1,
        'timezone' => is_string($v)
            && in_array($v, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true),
        'color'    => is_string($v)
            && preg_match('/^#(?:[0-9A-Fa-f]{3,4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $v) === 1,
        // Pas de contrôle de « liste » : `in_array` se moque des clés, et
        // array_is_list() exigerait PHP 8.1 (le projet vise 8.0).
        'origins'  => is_array($v)
            && count(array_filter($v, 'is_string')) === count($v),
        'positive-int' => is_int($v) && $v > 0,
    };
    if (!$ok) {
        $configErrors[] = "$name : " . CONFIG_EXPECTED[$rule];
    }
}
if ($configErrors !== []) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Configuration invalide :\n- "
        . implode("\n- ", $configErrors) . "\n"
        . "Comparer config.php avec config.example.php (doc/DEPLOIEMENT.md §5).\n");
}
unset($configErrors, $name, $rule, $v, $ok);

// Fuseau pour le formatage des heures affichées. Validé ci-dessus, donc
// jamais de repli silencieux sur UTC.
date_default_timezone_set(SITE_TIMEZONE);

/**
 * Marge au-delà de laquelle un horodatage de nom de fichier est jugé faux.
 *
 * Une caméra qui perd son horloge (RTC vidée après coupure) peut pousser des
 * noms datés de plusieurs années dans le futur. Comme l'heure vient du nom,
 * cette image deviendrait la plus récente — donc l'ancre du cleanup et de la
 * fenêtre. On la refuse en amont.
 *
 * La marge est large à dessein : un fuseau caméra mal réglé décale de
 * quelques heures, ce qui reste exploitable, alors qu'une horloge perdue
 * décale d'années. Le vrai filet de sécurité contre l'effacement reste
 * `anchor_ts()`, qui ne dépasse jamais l'heure courante.
 */
const FUTURE_TOLERANCE_SECONDS = 86400;

/**
 * Fenêtre timelapse réellement exploitable.
 *
 * WINDOW_SECONDS et RETENTION_SECONDS sont indépendants, mais dans un seul
 * sens : on ne peut pas rejouer ce que le cleanup a déjà supprimé. Une
 * fenêtre plus large que la rétention serait tronquée silencieusement — on
 * la borne ici pour que l'affichage n'annonce jamais une durée indisponible.
 *
 * Garder RETENTION >= WINDOW pour utiliser la fenêtre configurée.
 */
define('EFFECTIVE_WINDOW_SECONDS', min(WINDOW_SECONDS, RETENTION_SECONDS));

/**
 * Racine du dossier des snapshots : sous-dossier snap/ à côté de l'app.
 * La caméra y pousse ses images par FTP ; aucun code applicatif dedans.
 * C'est le `.htaccess` de la racine — il n'y en a pas d'autre — qui n'y
 * laisse servir que les `.jpg` du contrat de nommage. Même layout en dev
 * et en prod.
 */
function snap_root(): string
{
    return __DIR__ . '/snap';
}

/**
 * Motif du contrat de nommage caméra, horodatage capturé en \1.
 *
 * Source unique : c'est lui qui filtre les noms acceptés dans tout le projet.
 * Strictement ancré et insensible à la casse. `preg_quote` neutralise un
 * préfixe qui contiendrait des métacaractères — même si la validation de
 * config (règle 'prefix') les exclut déjà.
 */
function cam_filename_re(): string
{
    return '/^' . preg_quote(CAM_PREFIX, '/') . '_\d+_(\d{14})\.jpg$/i';
}

/**
 * Le nom suit-il le contrat de nommage, indépendamment de la validité de
 * son horodatage ?
 *
 * Distinction utile au seul cleanup : un fichier peut être manifestement un
 * upload caméra tout en portant une date inexploitable (horloge perdue). Il
 * faut alors pouvoir le supprimer sans pour autant s'arroger le droit de
 * supprimer n'importe quel fichier trouvé dans `snap/`. Voir cleanup_old().
 */
function is_cam_filename(string $filename): bool
{
    return preg_match(cam_filename_re(), $filename) === 1;
}

/**
 * Extrait l'epoch depuis un nom <CAM_PREFIX>_<canal>_AAAAMMJJhhmmss.jpg.
 * Source de vérité pour l'heure (indépendant de filemtime, faussé par FTP).
 *
 * Deux contrôles suivent le motif, parce qu'un nom bien formé peut mentir :
 * une date impossible (que `mktime` reporterait en silence) et un horodatage
 * trop loin dans le futur (horloge caméra perdue) sont refusés.
 *
 * @return int|null epoch, ou null si le nom ne correspond pas ou si son
 *                  horodatage est inexploitable.
 */
function ts_from_name(string $filename): ?int
{
    if (!preg_match(cam_filename_re(), $filename, $m)) {
        return null;
    }
    $d = $m[1];
    $an   = (int)substr($d, 0, 4);
    $mois = (int)substr($d, 4, 2);
    $jour = (int)substr($d, 6, 2);
    $h    = (int)substr($d, 8, 2);
    $min  = (int)substr($d, 10, 2);
    $sec  = (int)substr($d, 12, 2);

    // Sans ce contrôle, mktime reporte en silence : 20260231 -> 3 mars,
    // 20261345996060 -> février 2027. Un nom corrompu donnerait une heure
    // parfaitement crédible.
    if (!checkdate($mois, $jour, $an) || $h > 23 || $min > 59 || $sec > 59) {
        return null;
    }

    $ts = mktime($h, $min, $sec, $mois, $jour, $an);
    if ($ts === false) {
        return null;
    }

    // Horloge caméra déréglée : voir FUTURE_TOLERANCE_SECONDS.
    return $ts > time() + FUTURE_TOLERANCE_SECONDS ? null : $ts;
}

/**
 * Liste les images d'un jour donné (dossier AAAA/MM/JJ), triées par ts croissant.
 *
 * @return array<int,array{path:string,name:string,ts:int}>
 */
function list_day(int $ts): array
{
    $root = snap_root();
    $dir = sprintf('%s/%s/%s/%s', $root, date('Y', $ts), date('m', $ts), date('d', $ts));
    return list_day_dir($dir);
}

/**
 * Liste les images valides d'un dossier jour, triées par ts croissant.
 *
 * @return array<int,array{path:string,name:string,ts:int}>
 */
function list_day_dir(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $out = [];
    foreach (scandir($dir) ?: [] as $name) {
        $ts2 = ts_from_name($name);
        if ($ts2 === null) {
            continue;
        }
        $out[] = ['path' => $dir . '/' . $name, 'name' => $name, 'ts' => $ts2];
    }
    usort($out, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $out;
}

/**
 * Sous-dossiers de $dir dont le nom matche $pattern (ex. années, mois, jours),
 * triés par nom. Les noms AAAA/MM/JJ étant zéro-paddés, l'ordre lexical
 * est l'ordre chronologique.
 *
 * @return array<int,string> Noms de dossiers (pas les chemins complets).
 */
function scan_dirs(string $dir, string $pattern, bool $desc = false): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $sort = $desc ? SCANDIR_SORT_DESCENDING : SCANDIR_SORT_ASCENDING;
    $out = [];
    foreach (scandir($dir, $sort) ?: [] as $name) {
        if (preg_match($pattern, $name) && is_dir($dir . '/' . $name)) {
            $out[] = $name;
        }
    }
    return $out;
}

/**
 * Images sur une fenêtre glissante [now - $seconds, now].
 * Triées par ts croissant (ancien → récent).
 *
 * Balaie **tous** les dossiers jour couverts par la fenêtre, pas seulement
 * celui de début et celui de fin : WINDOW_SECONDS est librement configurable,
 * et une fenêtre de plus de 24 h perdrait sinon les jours intermédiaires.
 * En régime normal (fenêtre 1 h) cela reste un ou deux dossiers.
 *
 * L'avance de curseur passe par `mktime` plutôt que par `+ 86400` : au
 * changement d'heure, un jour ne fait pas 86400 secondes.
 *
 * @return array<int,array{path:string,name:string,ts:int}>
 */
function list_window(int $seconds, ?int $now = null): array
{
    $now ??= time();
    $start = $now - $seconds;

    $merged = [];
    $cursor = mktime(0, 0, 0, (int)date('n', $start), (int)date('j', $start), (int)date('Y', $start));
    while ($cursor !== false && $cursor <= $now) {
        foreach (list_day($cursor) as $img) {
            $merged[] = $img;
        }
        $cursor = mktime(0, 0, 0, (int)date('n', $cursor), (int)date('j', $cursor) + 1, (int)date('Y', $cursor));
    }

    $win = array_filter($merged, fn($i) => $i['ts'] >= $start && $i['ts'] <= $now);
    usort($win, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return array_values($win);
}

/**
 * Dernière image disponible, quelle que soit son ancienneté : parcourt
 * l'arbre AAAA/MM/JJ du plus récent au plus ancien et s'arrête au premier
 * jour contenant des images valides. Le cleanup conservant la dernière
 * heure connue même caméra coupée, on la retrouve ici sans limite de durée.
 *
 * @return array{path:string,name:string,ts:int}|null
 */
function latest_image(): ?array
{
    $root = snap_root();
    foreach (scan_dirs($root, '/^\d{4}$/', true) as $y) {
        foreach (scan_dirs("$root/$y", '/^\d{2}$/', true) as $m) {
            foreach (scan_dirs("$root/$y/$m", '/^\d{2}$/', true) as $d) {
                $imgs = list_day_dir("$root/$y/$m/$d");
                if ($imgs !== []) {
                    return end($imgs) ?: null;
                }
            }
        }
    }
    return null;
}

/**
 * Ancre temporelle commune au cleanup et à la fenêtre timelapse.
 *
 * On se cale sur la dernière image plutôt que sur `time()` : si la caméra
 * s'arrête, la dernière fenêtre connue reste rejouable au lieu d'être effacée
 * (voir cleanup_old dans images.php).
 *
 * Mais on ne dépasse jamais l'heure courante. Une caméra dont l'horloge est
 * en avance produirait sinon une ancre dans le futur, et le cleanup — dont le
 * seuil vaut « ancre moins rétention » — effacerait tout l'historique valide
 * d'un coup. Le `min` rend cette perte impossible par construction, quelle
 * que soit la confiance qu'on accorde aux noms de fichiers.
 *
 * @param array{path:string,name:string,ts:int}|null $last Dernière image, ou null.
 */
function anchor_ts(?array $last, ?int $now = null): int
{
    $now ??= time();
    return $last === null ? $now : min($last['ts'], $now);
}

/**
 * Chemin web d'une image pour le JSON : snap/AAAA/MM/JJ/xxx.jpg, relatif
 * à la page (index.php vit à côté du dossier snap/).
 */
function web_path(string $absPath): string
{
    $root = snap_root();
    // Strip du préfixe racine uniquement (pas un str_replace global).
    $rel = str_starts_with($absPath, $root)
        ? substr($absPath, strlen($root))
        : $absPath;
    return 'snap/' . ltrim($rel, '/');
}
