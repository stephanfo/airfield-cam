<?php
/**
 * images.php — liste JSON des images de la dernière heure + cleanup paresseux.
 *
 * Source du timelapse et du refresh de l'image courante.
 * Pas de cron : ce script supprime aussi les images de plus d'1h à chaque appel.
 * Voir doc/TECHNIQUE.md §3, §4.
 *
 * Copyright (C) 2026 Stephanfo
 *
 * Ce programme est un logiciel libre : vous pouvez le redistribuer et/ou le
 * modifier selon les termes de la GNU Affero General Public License version 3,
 * telle que publiée par la Free Software Foundation. Distribué sans aucune
 * garantie — voir le fichier LICENSE.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

// --- CORS : autorise des applis tierces à consommer la liste JSON depuis une
//     autre origine. Liste blanche définie en config (lecture seule, données
//     non sensibles). `Vary: Origin` pour ne pas empoisonner les caches.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, CORS_ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}

// Fenêtre, rétention et borne anti-abus : voir config.example.php.

$now = time();

// Dernière image disponible : sert d'ancre au cleanup ET à la fenêtre.
$last = latest_image();

// Ancre commune : dernière image, jamais au-delà de l'heure courante
// (voir anchor_ts — c'est ce qui protège l'archive d'une horloge caméra
// en avance).
$anchor = anchor_ts($last, $now);

// --- Cleanup paresseux : supprime les images plus vieilles que la rétention,
//     par rapport à l'ancre (voir cleanup_old).
//     Throttlé à 1 passage/min : l'endpoint est public, sans throttle un
//     visiteur peut déclencher scandir+unlink à volonté.
// Le nom du verrou inclut un hash du chemin d'installation : sur hébergement
// mutualisé, sys_get_temp_dir() est partagé, et deux instances du projet se
// voleraient mutuellement leur créneau de cleanup.
$lock = sys_get_temp_dir() . '/webcam_cleanup_'
    . substr(hash('sha256', __DIR__), 0, 12) . '.ts';
// Pas de garde sur $last : quand aucune image lisible n'existe, anchor_ts
// renvoie l'heure courante, et c'est précisément le cas où il faut nettoyer
// — un dossier plein de fichiers aux horodatages inexploitables ne doit pas
// geler la rétention.
if (!is_file($lock) || $now - (int)filemtime($lock) >= 60) {
    // Le verrou est un throttle, jamais une condition de correction. S'il ne
    // peut pas être écrit (tmp non inscriptible, open_basedir), @touch échoue,
    // is_file reste faux et le ménage a lieu à chaque requête : dégradé, mais
    // vivant. L'inverse — sauter le ménage faute de verrou — désactivait la
    // rétention en silence, et sans cron plus rien ne supprime : le dossier
    // grossit jusqu'à saturer le quota. Un scandir non throttlé sur un arbre
    // d'un à deux dossiers jour est le moindre mal.
    @touch($lock);
    cleanup_old($anchor, RETENTION_SECONDS, $now);
}

// --- Fenêtre ancrée sur la dernière image (pas sur l'heure courante), pour
//     que le timelapse reste rejouable même si la caméra s'est arrêtée.
//     Fenêtre bornée par la rétention (voir EFFECTIVE_WINDOW_SECONDS).
$imgs = list_window(EFFECTIVE_WINDOW_SECONDS, $anchor);

// Borne le nombre renvoyé (garde les plus récentes).
if (count($imgs) > MAX_IMAGES) {
    $imgs = array_slice($imgs, -MAX_IMAGES);
}

$out = array_map(static function (array $i): array {
    return [
        'url'  => web_path($i['path']),
        'ts'   => $i['ts'],
        'time' => date('H:i:s', $i['ts']),
    ];
}, $imgs);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'now'     => $now,                  // heure serveur (pour calcul fraîcheur)
    'last_ts' => $last['ts'] ?? null,   // epoch de la dernière image
    'count'   => count($out),
    'images'  => $out,
], JSON_UNESCAPED_SLASHES);

/**
 * Supprime les images plus vieilles que $retention par rapport à $anchor,
 * l'ancre fournie par anchor_ts() : la dernière image disponible, bornée à
 * l'heure courante.
 *
 * Se caler sur la dernière image plutôt que sur `time()` fait que, si la
 * caméra s'arrête, on conserve la dernière heure d'images connue au lieu de
 * vider le dossier : le timelapse survit à une coupure. La borne haute, elle,
 * évite le cas inverse — une horloge caméra en avance qui ferait tout effacer.
 *
 * Balaie tout l'arbre AAAA/MM/JJ (couvre une reprise après plusieurs jours
 * de coupure, pas seulement J/J-1) et supprime au passage les dossiers
 * jour/mois/année devenus vides. L'arbre reste minuscule en régime normal
 * (rétention 1h → 1-2 dossiers jour). Confiné à snap_root().
 *
 * Deux régimes de suppression, sur **deux horloges différentes** :
 *
 *   - horodatage lisible → comparé à `$anchor - $retention`, donc à l'horloge
 *     de la caméra, celle qui a écrit le nom ;
 *   - horodatage inexploitable (date impossible, ou horloge caméra perdue
 *     datant le fichier dans le futur) → comparé à `filemtime`, donc à
 *     l'horloge du serveur, celle qui a reçu l'upload FTP.
 *
 * Mélanger les deux — purger sur `filemtime` avec un seuil dérivé de $anchor,
 * ou l'inverse — supprimerait trop tôt ou jamais. Ce second régime existe
 * parce que sans lui ces fichiers étaient **indélébiles** : invisibles à
 * `list_day_dir`, donc jamais candidats à la suppression, et assez nombreux
 * après une panne d'horloge pour saturer le disque. `filemtime` reste
 * inutilisable pour *dater* une image (l'upload FTP le fausse), mais reste un
 * repère acceptable pour décider qu'un fichier illisible a fait son temps.
 *
 * Ne touche que les noms conformes au contrat (`is_cam_filename`) : ce qui
 * n'est pas un upload caméra n'est jamais supprimé, quel que soit son âge.
 * Conséquence à connaître — après un changement de CAM_PREFIX, les anciennes
 * images ne correspondent plus au contrat et ne sont plus nettoyées (voir
 * doc/DEPLOIEMENT.md §3).
 *
 * @param int $anchor Ancre temporelle (epoch), issue de anchor_ts().
 */
function cleanup_old(int $anchor, int $retention, ?int $now = null): void
{
    $now ??= time();
    $cutoff = $anchor - $retention;       // horloge caméra (nom de fichier)
    $mtimeCutoff = $now - $retention;     // horloge serveur (réception FTP)
    $root = snap_root();

    foreach (scan_dirs($root, '/^\d{4}$/') as $y) {
        foreach (scan_dirs("$root/$y", '/^\d{2}$/') as $m) {
            foreach (scan_dirs("$root/$y/$m", '/^\d{2}$/') as $d) {
                $dir = "$root/$y/$m/$d";
                foreach (scandir($dir) ?: [] as $name) {
                    $path = $dir . '/' . $name;
                    if (!is_cam_filename($name) || !is_file($path)) {
                        continue;
                    }
                    $ts = ts_from_name($name);
                    if ($ts === null) {
                        $mtime = @filemtime($path);
                        if ($mtime !== false && $mtime < $mtimeCutoff) {
                            @unlink($path);
                        }
                    } elseif ($ts < $cutoff) {
                        @unlink($path);
                    }
                }
                @rmdir($dir); // no-op si non vide
            }
            @rmdir("$root/$y/$m");
        }
        @rmdir("$root/$y");
    }
}
