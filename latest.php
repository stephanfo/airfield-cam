<?php
/**
 * latest.php — streame la dernière image webcam disponible.
 *
 * URL stable pour applis tierces : renvoie toujours la dernière image JPEG.
 * Exposable en latest.jpg via réécriture Apache.
 * Voir doc/TECHNIQUE.md §3.
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

// --- CORS : même liste blanche que images.php. Inutile pour une balise <img>
//     (jamais soumise au CORS), indispensable dès qu'une appli tierce lit
//     l'image par fetch()/XHR — typiquement pour la retraiter avant affichage.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, CORS_ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}

$img = latest_image();

if ($img === null || !is_file($img['path'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Aucune image disponible.";
    exit;
}

// Garde-fou : le fichier servi doit être sous la racine des snapshots.
// Slash final pour éviter qu'un dossier frère préfixé (/a/snapXXX) passe.
$root = realpath(snap_root());
$real = realpath($img['path']);
if ($root === false || $real === false
    || !str_starts_with($real, rtrim($root, '/') . '/')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Accès refusé.";
    exit;
}

// Anti-cache : l'image courante doit toujours être fraîche.
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($real));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// Nom horodaté proposé si téléchargement direct. CAM_PREFIX est interpolé
// sans échappement : ce qui rend sûr cet en-tête est la validation de config
// (CONFIG_SPEC, règle 'prefix'), qui exclut guillemet, point-virgule et CR/LF.
header('Content-Disposition: inline; filename="'
    . CAM_PREFIX . '_' . date('Ymd_His', $img['ts']) . '.jpg"');

readfile($real);
