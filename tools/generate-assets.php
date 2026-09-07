<?php
/**
 * Génère les assets placeholder du projet : logo, favicon, icône iOS et
 * image d'aperçu Open Graph. Pictogramme neutre (caméra au-dessus d'une
 * piste), sans identité de club — à remplacer par vos propres assets.
 *
 * Usage :  php tools/generate-assets.php .
 *
 * Rendu en 4x puis réduction, pour un anticrénelage propre. PHP/GD
 * uniquement, aucune dépendance (cohérent avec la contrainte du projet).
 * Le pendant vectoriel du dessin est dans tools/logo.svg.
 *
 * Les couleurs reprennent THEME_COLOR / THEME_ACCENT de config.example.php.
 *
 * Copyright (C) 2026 Stephanfo
 *
 * Ce programme est un logiciel libre : vous pouvez le redistribuer et/ou le
 * modifier selon les termes de la GNU Affero General Public License version 3,
 * telle que publiée par la Free Software Foundation. Distribué sans aucune
 * garantie — voir le fichier LICENSE.
 */
declare(strict_types=1);

// imagefilledpolygon() sous sa forme à 3 arguments : PHP 8.1+. L'application
// elle-même tourne sur 8.0 ; seul cet outil de génération demande plus. Sans
// cette garde, l'échec est un ArgumentCountError au premier tracé, après
// plusieurs secondes de rendu et sans un mot sur la cause.
if (PHP_VERSION_ID < 80100) {
    exit("PHP 8.1+ requis pour cet outil (PHP " . PHP_VERSION . " détecté).\n");
}

if (!extension_loaded('gd')) {
    exit("Extension GD requise.\n");
}

const BLEU       = [0x04, 0x5B, 0xCB];
const BLEU_CLAIR = [0x0A, 0x72, 0xF0];
const BLEU_NUIT  = [0x0A, 0x3F, 0x8F];
const BLANC      = [0xFF, 0xFF, 0xFF];
const GRIS       = [0xE8, 0xEE, 0xF7];
const VERT       = [0xAE, 0xCD, 0x47];

function col($im, array $c) { return imagecolorallocate($im, $c[0], $c[1], $c[2]); }

/** Rectangle à coins arrondis. */
function roundRect($im, float $x, float $y, float $w, float $h, float $r, $c): void
{
    imagefilledrectangle($im, (int)($x+$r), (int)$y, (int)($x+$w-$r), (int)($y+$h), $c);
    imagefilledrectangle($im, (int)$x, (int)($y+$r), (int)($x+$w), (int)($y+$h-$r), $c);
    foreach ([[$x+$r,$y+$r],[$x+$w-$r,$y+$r],[$x+$r,$y+$h-$r],[$x+$w-$r,$y+$h-$r]] as $p) {
        imagefilledellipse($im, (int)$p[0], (int)$p[1], (int)($r*2), (int)($r*2), $c);
    }
}

/**
 * Dessine la scène : ciel, sol, piste en perspective, caméra sur son support.
 * $w/$h = dimensions cibles ; le dessin s'adapte proportionnellement.
 * $rounded = coins arrondis (logo) ; $full = fond plein bord à bord (og-image).
 */
function scene(int $w, int $h, bool $rounded): \GdImage
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    $transp = imagecolorallocatealpha($im, 0, 0, 0, 127);
    imagefill($im, 0, 0, $transp);

    $r = $rounded ? $w * 0.058 : 0.0;

    // Ciel (dégradé vertical simple)
    $skyH = (int)round($h * 0.773);
    for ($y = 0; $y < $skyH; $y++) {
        $t = $y / max(1, $skyH - 1);
        $c = imagecolorallocate($im,
            (int)round(BLEU[0] + ($t * (BLEU_CLAIR[0] - BLEU[0]))),
            (int)round(BLEU[1] + ($t * (BLEU_CLAIR[1] - BLEU[1]))),
            (int)round(BLEU[2] + ($t * (BLEU_CLAIR[2] - BLEU[2]))));
        imageline($im, 0, $y, $w - 1, $y, $c);
    }
    // Sol
    imagefilledrectangle($im, 0, $skyH, $w - 1, $h - 1, col($im, BLEU_NUIT));

    // Piste en perspective (trapèze du sol vers le bas)
    $cx = $w / 2;
    $pisteBas = $rounded ? (int)($h - $r*0.55) : $h;
    $piste = [
        (int)($cx - $w*0.115), $pisteBas,
        (int)($cx - $w*0.054), $skyH,
        (int)($cx + $w*0.054), $skyH,
        (int)($cx + $w*0.115), $pisteBas,
    ];
    imagefilledpolygon($im, $piste, col($im, GRIS));

    // Marquages d'axe. Le dernier reste au-dessus du bord bas pour ne pas
    // être tronqué par le cadre.
    $mk = col($im, [0x5B, 0x77, 0xAD]);
    $marks = [[0.030, 0.017, 0.045], [0.100, 0.021, 0.050], [0.175, 0.027, 0.038]];
    foreach ($marks as [$off, $mw, $mh]) {
        $y0 = $skyH + $h * $off;
        roundRect($im, $cx - $w*$mw/2, $y0, $w*$mw, $h*$mh, $w*0.004, $mk);
    }

    // Coins arrondis en dernier : on efface hors du rectangle arrondi, sinon
    // les éléments dessinés après (piste, caméra) débordent du cadre.
    if ($rounded) {
        $mask = imagecreatetruecolor($w, $h);
        imagealphablending($mask, false);
        imagesavealpha($mask, true);
        imagefill($mask, 0, 0, imagecolorallocatealpha($mask, 0, 0, 0, 127));
        imagealphablending($mask, true);
        roundRect($mask, 0, 0, $w - 1, $h - 1, $r, imagecolorallocate($mask, 255, 0, 255));
        imagealphablending($im, false);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($mask, $x, $y) >> 24) & 0x7F) === 127) {
                    imagesetpixel($im, $x, $y, $transp);
                }
            }
        }
        imagealphablending($im, true);
    }

    // Support de la caméra
    $blanc = col($im, BLANC);
    roundRect($im, $cx - $w*0.017, $h*0.613, $w*0.033, $h*0.113, $w*0.004, $blanc);
    roundRect($im, $cx - $w*0.075, $h*0.713, $w*0.150, $h*0.047, $w*0.015, $blanc);

    // Caméra : corps
    $bx = $cx - $w*0.192; $by = $h*0.307;
    $bw = $w*0.313; $bh = $h*0.307;
    roundRect($im, $bx, $by, $bw, $bh, $w*0.037, $blanc);

    // Pare-soleil (trapèze à droite)
    imagefilledpolygon($im, [
        (int)($bx+$bw),          (int)($by+$bh*0.24),
        (int)($bx+$bw+$w*0.112), (int)($by-$bh*0.04),
        (int)($bx+$bw+$w*0.112), (int)($by+$bh*1.04),
        (int)($bx+$bw),          (int)($by+$bh*0.76),
    ], col($im, GRIS));

    // Objectif
    $ox = $bx + $bw*0.41; $oy = $by + $bh/2;
    imagefilledellipse($im, (int)$ox, (int)$oy, (int)($w*0.125), (int)($w*0.125), col($im, BLEU_NUIT));
    imagefilledellipse($im, (int)$ox, (int)$oy, (int)($w*0.079), (int)($w*0.079), col($im, BLEU));
    imagefilledellipse($im, (int)($ox - $w*0.015), (int)($oy - $w*0.017),
        (int)($w*0.029), (int)($w*0.029), $blanc);

    // Témoin d'enregistrement
    imagefilledellipse($im, (int)($bx + $bw*0.81), (int)($by + $bh*0.28),
        (int)($w*0.033), (int)($w*0.033), col($im, VERT));

    return $im;
}

/** Rend la scène en 4x puis réduit (anticrénelage). */
function render(int $w, int $h, bool $rounded): \GdImage
{
    $big = scene($w*4, $h*4, $rounded);
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopyresampled($out, $big, 0, 0, 0, 0, $w, $h, $w*4, $h*4);
    
    return $out;
}

$dir = $argv[1] ?? '.';

// --- logo.png : 480x300, coins arrondis, fond transparent hors cadre
$logo = render(480, 300, true);
imagepng($logo, "$dir/logo.png", 9);

// --- favicon.png : 32x32. À cette taille la scène complète devient illisible :
//     on ne garde que l'objectif de la caméra, sur fond plein.
function favicon(int $size): \GdImage
{
    $s = $size * 8;
    $im = imagecreatetruecolor($s, $s);
    imagealphablending($im, true);
    imagesavealpha($im, true);

    // Fond arrondi bleu
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    roundRect($im, 0, 0, $s - 1, $s - 1, $s * 0.18, col($im, BLEU));

    // Corps blanc de la caméra, cadré large
    roundRect($im, $s*0.12, $s*0.26, $s*0.60, $s*0.48, $s*0.09, col($im, BLANC));
    // Pare-soleil
    imagefilledpolygon($im, [
        (int)($s*0.72), (int)($s*0.38),
        (int)($s*0.90), (int)($s*0.24),
        (int)($s*0.90), (int)($s*0.76),
        (int)($s*0.72), (int)($s*0.62),
    ], col($im, GRIS));
    // Objectif
    imagefilledellipse($im, (int)($s*0.36), (int)($s*0.50), (int)($s*0.30), (int)($s*0.30), col($im, BLEU_NUIT));
    imagefilledellipse($im, (int)($s*0.36), (int)($s*0.50), (int)($s*0.17), (int)($s*0.17), col($im, BLEU));
    // Témoin
    imagefilledellipse($im, (int)($s*0.61), (int)($s*0.36), (int)($s*0.09), (int)($s*0.09), col($im, VERT));

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $s, $s);
    return $out;
}
imagepng(favicon(32), "$dir/favicon.png", 9);

// --- apple-touch-icon.png : 180x180, fond plein (iOS n'aime pas la transparence)
$ios = render(180, 180, false);
$flat = imagecreatetruecolor(180, 180);
imagefilledrectangle($flat, 0, 0, 180, 180, imagecolorallocate($flat, 0x04, 0x5B, 0xCB));
imagecopy($flat, $ios, 0, 0, 0, 0, 180, 180);
imagepng($flat, "$dir/apple-touch-icon.png", 9);

// --- og-image.jpg : 1200x630, fond plein bord à bord
$og = render(1200, 630, false);
$ogFlat = imagecreatetruecolor(1200, 630);
imagefilledrectangle($ogFlat, 0, 0, 1200, 630, imagecolorallocate($ogFlat, 0x04, 0x5B, 0xCB));
imagecopy($ogFlat, $og, 0, 0, 0, 0, 1200, 630);
imagejpeg($ogFlat, "$dir/og-image.jpg", 88);

echo "assets générés dans $dir\n";
