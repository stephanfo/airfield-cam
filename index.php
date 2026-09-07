<?php
/**
 * index.php — page de consultation de la webcam.
 *
 * Vue Live (dernière image, auto-refresh, download, URL stable pour applis)
 * et mode Timelapse (rejeu de la dernière heure, lecture/pause, vitesse,
 * navigation pas-à-pas, slider).
 *
 * Endpoints associés : latest.php (image stable), images.php (liste JSON).
 * Textes et réglages : config.php. Voir doc/PRD.md et doc/TECHNIQUE.md.
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

/** Échappement HTML des valeurs de config injectées dans la page. */
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Libellé de la fenêtre timelapse (ex. « 45 s », « 30 min », « 1 h 30 min »).
// Dérivé de la fenêtre EFFECTIVE : si la rétention est plus courte que
// WINDOW_SECONDS, on annonce ce qu'on peut réellement rejouer.
//
// La branche « secondes » n'est pas théorique : WINDOW_SECONDS est libre, et
// toute valeur sous 60 affichait « 0 min » — dans l'onglet comme dans le
// message d'état vide.
$windowLabel = (static function (int $s): string {
    if ($s < 60) {
        return $s . ' s';
    }
    $min = intdiv($s, 60);
    if ($min < 60) {
        return $min . ' min';
    }
    $heures = intdiv($min, 60);
    $reste  = $min % 60;
    return $reste === 0 ? $heures . ' h' : $heures . ' h ' . $reste . ' min';
})(EFFECTIVE_WINDOW_SECONDS);

/**
 * Teintes dérivées de THEME_COLOR / THEME_ACCENT.
 *
 * Les couleurs de base venaient de la config, mais tout ce qui en dérivait
 * restait en dur : le survol repassait au bleu d'origine, et le thème sombre
 * écrasait purement THEME_COLOR — une instance aux couleurs personnalisées
 * n'en voyait donc aucune trace chez les visiteurs en mode sombre.
 *
 * Calculées en PHP plutôt qu'avec `color-mix()` en CSS : la valeur est déjà
 * validée comme littéral hexadécimal (CONFIG_SPEC), le calcul est trivial, et
 * le résultat ne demande ni `@supports` ni repli en dur — un repli en dur
 * étant précisément le bug corrigé ici.
 *
 * Un éventuel canal alpha de la config n'est pas repris dans les dérivées :
 * survol et voile ont leur propre opacité.
 *
 * @param float $keep   Part de la couleur conservée (0.8 = 80 %).
 * @param int   $toward Cible du mélange : 0 pour assombrir, 255 pour éclaircir.
 */
$rgb = static function (string $hex): array {
    $x = ltrim($hex, '#');
    if (strlen($x) === 3 || strlen($x) === 4) {
        $x = (string)preg_replace('/(.)/', '$1$1', $x);
    }
    return [hexdec(substr($x, 0, 2)), hexdec(substr($x, 2, 2)), hexdec(substr($x, 4, 2))];
};
$shade = static function (string $hex, float $keep, int $toward) use ($rgb): string {
    $m = static fn(int $c): int => (int)round($keep * $c + (1 - $keep) * $toward);
    [$r, $g, $b] = $rgb($hex);
    return sprintf('#%02x%02x%02x', $m($r), $m($g), $m($b));
};
/** Voile translucide de la couleur (fonds de survol discrets). */
$tint = static function (string $hex, float $alpha) use ($rgb): string {
    [$r, $g, $b] = $rgb($hex);
    return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim(rtrim(number_format($alpha, 3, '.', ''), '0'), '.'));
};

// Open Graph : les crawlers ne résolvent pas le relatif, d'où SITE_BASE_URL.
// SITE_OG_IMAGE accepte les deux formes — chemin relatif à SITE_BASE_URL, ou
// URL absolue (image hébergée sur un CDN). Préfixer une absolue donnerait
// https://site.exemple/https://cdn.exemple/preview.jpg, et l'aperçu de lien
// n'afficherait aucune image.
// $ogBase normalise aussi le slash final que og:url omettait quand
// SITE_BASE_URL était configurée sans (og:image, lui, le corrigeait déjà :
// les deux balises annonçaient alors des URLs incohérentes).
// Variante éclaircie de THEME_COLOR pour le thème sombre : calculée une fois
// ici, le bloc @media s'en sert trois fois.
$bleuSombre = $shade(THEME_COLOR, .7, 255);

$ogBase  = rtrim(SITE_BASE_URL, '/') . '/';
$ogImage = preg_match('~^(?:https?:)?//~i', SITE_OG_IMAGE) === 1
    ? SITE_OG_IMAGE
    : $ogBase . ltrim(SITE_OG_IMAGE, '/');

// En-têtes de sécurité. CSP : tout vient de la page elle-même (JS/CSS inline,
// images et fetch même origine) — aucune ressource externe autorisée.
header("Content-Security-Policy: default-src 'none'; img-src 'self'; "
    . "script-src 'unsafe-inline'; style-src 'unsafe-inline'; "
    . "connect-src 'self'; base-uri 'none'; form-action 'none'; "
    . "frame-ancestors 'self'");
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $h(SITE_TITLE) ?></title>
<meta name="description" content="<?= $h(SITE_DESCRIPTION) ?>">
<meta name="theme-color" content="<?= $h(THEME_COLOR) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="favicon.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<?php if (SITE_BASE_URL !== ''): ?>
<!-- Aperçu de lien (WhatsApp, iMessage, réseaux sociaux). Les crawlers OG ne
     résolvent pas les chemins relatifs : URLs absolues obligatoires, d'où
     SITE_BASE_URL en config. -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $h(SITE_TITLE) ?>">
<meta property="og:description" content="<?= $h(SITE_OG_DESCRIPTION) ?>">
<meta property="og:url" content="<?= $h($ogBase) ?>">
<meta property="og:image" content="<?= $h($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<?php endif; ?>
<style>
  /* THEME_COLOR / THEME_ACCENT : la garantie vient de la validation de
     config (CONFIG_SPEC, règle 'color' dans lib.php), pas de $h() —
     <style> est un élément à texte brut, l'échappement HTML n'y neutralise
     aucune syntaxe CSS. */
  :root {
    --bleu: <?= $h(THEME_COLOR) ?>;
    --bleu-fonce: <?= $shade(THEME_COLOR, .8, 0) ?>;
    --bleu-voile: <?= $tint(THEME_COLOR, .08) ?>;
    --vert: <?= $h(THEME_ACCENT) ?>;
    --vert-fonce: <?= $shade(THEME_ACCENT, .8, 0) ?>;

    /* Thème clair par défaut */
    --bg: #f4f6f9;
    --surface: #ffffff;
    --texte: #1b2230;
    --texte-faible: #5b6675;
    --bordure: #e2e7ee;
    --ombre: 0 1px 3px rgba(16, 28, 52, .12), 0 8px 24px rgba(16, 28, 52, .06);
  }
  @media (prefers-color-scheme: dark) {
    :root {
      /* Éclairci pour rester lisible sur fond sombre, mais dérivé de
         THEME_COLOR : la valeur en dur qui était ici privait de toute couleur
         personnalisée les visiteurs en mode sombre. Le facteur 0.7 reproduit
         le contraste de l'ancienne valeur (5.0 sur --surface, AA).
         Les trois teintes sont redéfinies explicitement — --bleu-fonce et
         --bleu-voile portent des littéraux, pas des var(--bleu), donc rien
         ne suit tout seul. */
      --bleu: <?= $bleuSombre ?>;
      --bleu-fonce: <?= $shade($bleuSombre, .8, 0) ?>;
      --bleu-voile: <?= $tint($bleuSombre, .12) ?>;
      --bg: #0d1117;
      --surface: #161b22;
      --texte: #e6edf3;
      --texte-faible: #9aa7b4;
      --bordure: #2a313c;
      --ombre: 0 1px 3px rgba(0, 0, 0, .4), 0 8px 24px rgba(0, 0, 0, .3);
    }
  }

  * { box-sizing: border-box; }
  html, body { margin: 0; }
  /* L'attribut hidden doit gagner même sur les éléments display:flex
     (la règle UA est écrasée par tout display auteur sinon). */
  [hidden] { display: none !important; }

  /* Icônes SVG inline : monochromes (currentColor), rendu identique
     iOS/desktop — remplace les émojis aux glyphes disparates. */
  .ico { width: 1.1em; height: 1.1em; flex: none; display: block; }
  button, .btn, .tab, .ctrl, .speed {
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
  }
  body {
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    background: var(--bg);
    color: var(--texte);
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
  }

  header {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .75rem 1rem;
    /* Safe areas hors media query : viewport-fit=cover s'applique aussi
       en paysage (>640px), l'encoche ne doit jamais manger le contenu. */
    padding-left: max(1rem, env(safe-area-inset-left));
    padding-right: max(1rem, env(safe-area-inset-right));
    background: var(--surface);
    border-bottom: 1px solid var(--bordure);
  }
  header img.logo { height: 44px; width: auto; }
  header h1 {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--bleu);
    margin: 0;
  }

  main {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1rem;
  }

  .viewer {
    background: var(--surface);
    border: 1px solid var(--bordure);
    border-radius: 12px;
    box-shadow: var(--ombre);
    overflow: hidden;
  }
  .frame {
    position: relative;
    background: #000;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
  }
  .frame img {
    display: block;
    width: 100%;
    height: auto;
    max-height: 78vh;
    object-fit: contain;
  }
  .frame .msg {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    color: #fff;
    background: rgba(0,0,0,.6);
    padding: 1rem;
    text-align: center;
    font-size: .95rem;
  }
  .frame.error .msg { display: flex; }

  .meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .5rem 1rem;
    padding: .6rem .9rem;
    border-top: 1px solid var(--bordure);
    font-size: .9rem;
    color: var(--texte-faible);
  }
  .meta .stamp { color: var(--texte); font-weight: 600; }
  .meta .dot {
    width: .55rem; height: .55rem; border-radius: 50%;
    background: var(--vert);
    box-shadow: 0 0 0 0 rgba(174,205,71,.6);
    animation: pulse 2s infinite;
  }
  @keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(174,205,71,.5); }
    70% { box-shadow: 0 0 0 .5rem rgba(174,205,71,0); }
    100% { box-shadow: 0 0 0 0 rgba(174,205,71,0); }
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    margin-top: 1rem;
  }
  .btn {
    appearance: none;
    border: 1px solid transparent;
    border-radius: 9px;
    padding: .6rem 1rem;
    font-size: .95rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    color: #fff;
    background: var(--bleu);
    transition: background .15s, transform .05s;
  }
  .btn:hover { background: var(--bleu-fonce); }
  .btn:active { transform: translateY(1px); }
  .btn.secondary {
    background: transparent;
    color: var(--bleu);
    border-color: var(--bordure);
  }
  .btn.secondary:hover { background: var(--bleu-voile); }
  .btn.accent { background: var(--vert); color: #1b2230; }
  .btn.accent:hover { background: var(--vert-fonce); }

  .api {
    margin-top: 1.25rem;
    padding: .85rem 1rem;
    background: var(--surface);
    border: 1px solid var(--bordure);
    border-radius: 10px;
    font-size: .85rem;
    color: var(--texte-faible);
  }
  .api .api-label { display: block; margin-bottom: .35rem; }
  .api-status { margin-left: .4rem; font-weight: 600; }
  .api-status.err { color: #d9534f; }
  .api-row { display: flex; align-items: center; gap: .5rem; }
  .api code {
    flex: 1;
    min-width: 0;
    background: var(--bleu-voile);
    color: var(--bleu);
    padding: .3rem .45rem;
    border-radius: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    /* URL longue : ellipse sur une ligne. user-select: all → un tap
       sélectionne l'URL entière (copie manuelle si bouton caché). */
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    -webkit-user-select: all;
    user-select: all;
  }
  /* Bouton copier « fantôme » : icône grise sans bordure, discret. */
  .api button {
    flex: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--texte-faible);
    cursor: pointer;
  }
  .api button:hover { background: var(--bleu-voile); color: var(--bleu); }
  .api button .ico { width: 1em; height: 1em; }
  #copy.ok { color: var(--vert-fonce); }
  /* Bascule copier → coche pendant le feedback (classe .ok posée par le JS). */
  #copy .ico-check { display: none; }
  #copy.ok .ico-copy { display: none; }
  #copy.ok .ico-check { display: block; }

  footer {
    text-align: center;
    padding: 1.5rem 1rem;
    padding-bottom: calc(1.5rem + env(safe-area-inset-bottom));
    color: var(--texte-faible);
    font-size: .8rem;
  }

  /* --- Onglets Live / Timelapse --- */
  .tabs {
    display: inline-flex;
    gap: .25rem;
    padding: .25rem;
    margin-bottom: 1rem;
    background: var(--surface);
    border: 1px solid var(--bordure);
    border-radius: 10px;
  }
  .tab {
    appearance: none;
    border: none;
    background: transparent;
    color: var(--texte-faible);
    font-size: .95rem;
    font-weight: 600;
    padding: .45rem .9rem;
    border-radius: 7px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
  }
  .tab.active { background: var(--bleu); color: #fff; }
  .tabdot {
    width: .55rem; height: .55rem; border-radius: 50%;
    background: var(--vert);
    flex: none;
  }

  /* Sélection du mode affiché */
  body[data-mode="live"] .only-timelapse { display: none; }
  body[data-mode="timelapse"] .only-live { display: none; }

  /* --- Contrôles timelapse --- */
  .controls {
    margin-top: 1rem;
    background: var(--surface);
    border: 1px solid var(--bordure);
    border-radius: 12px;
    box-shadow: var(--ombre);
    padding: .9rem 1rem;
  }
  .controls .row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .5rem;
  }
  .controls .row + .row { margin-top: .75rem; }
  .timeline {
    flex: 1 1 220px;
    min-width: 180px;
    accent-color: var(--bleu);
  }
  .ctrl {
    appearance: none;
    border: 1px solid var(--bordure);
    background: var(--surface);
    color: var(--texte);
    font-size: 1rem;
    font-weight: 600;
    min-width: 2.6rem;
    height: 2.6rem;
    padding: 0 .7rem;
    border-radius: 9px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .ctrl .ico { width: 1.2em; height: 1.2em; }
  .ctrl:hover { background: var(--bleu-voile); }
  .ctrl.play { background: var(--bleu); color: #fff; border-color: transparent; min-width: 3.2rem; }
  .ctrl.play:hover { background: var(--bleu-fonce); }
  /* Bouton play/pause : l'icône affichée suit l'état .playing posé par le JS. */
  #tl-play .ico-pause { display: none; }
  #tl-play.playing .ico-play { display: none; }
  #tl-play.playing .ico-pause { display: block; }
  .speeds { display: inline-flex; gap: .25rem; }
  .speed {
    appearance: none;
    border: 1px solid var(--bordure);
    background: var(--surface);
    color: var(--texte-faible);
    font-size: .85rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .3rem;
    height: 2rem;
    padding: 0 .55rem;
    border-radius: 7px;
    cursor: pointer;
  }
  .speed.active { background: var(--vert); color: #1b2230; border-color: transparent; }
  .pos {
    font-variant-numeric: tabular-nums;
    color: var(--texte-faible);
    font-size: .85rem;
    margin-left: auto;
  }
  .spacer { flex: 1; }
  .lbl { color: var(--texte-faible); font-size: .85rem; }

  /* --- Bandeau d'avertissement (image périmée / cam HS) --- */
  .warn {
    display: none;
    align-items: center;
    gap: .6rem;
    margin-top: .75rem;
    padding: .7rem .9rem;
    border-radius: 10px;
    background: #fff4e5;
    border: 1px solid #f0b357;
    color: #8a5300;
    font-size: .9rem;
    font-weight: 600;
  }
  .warn.show { display: flex; }
  .warn .ico { width: 1.2em; height: 1.2em; }
  @media (prefers-color-scheme: dark) {
    .warn {
      background: rgba(240, 179, 87, .12);
      border-color: #a9772b;
      color: #f0b357;
    }
  }

  /* --- Mobile : layout bord à bord, contrôles en grille, cibles 44px+ --- */
  @media (max-width: 640px) {
    header img.logo { height: 32px; }
    header h1 {
      font-size: .95rem;
      min-width: 0; /* enfant flex : autorise le rétrécissement → ellipsis */
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    main { padding: 0 0 1rem; }

    .tabs {
      display: flex;
      width: 100%;
      margin-bottom: .75rem;
      border-radius: 0;
      border-left: none;
      border-right: none;
    }
    .tab { flex: 1; min-height: 44px; }

    .viewer, .controls, .warn, .api {
      border-radius: 0;
      border-left: none;
      border-right: none;
    }
    .frame img { max-height: 70vh; }
    .meta { padding: .6rem 1rem; }
    .warn { padding: .7rem 1rem; }
    .api { padding: .85rem 1rem; }

    .actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .6rem;
      padding: 0 1rem;
    }
    .actions .btn { justify-content: center; min-height: 44px; }

    .controls { padding: 1rem; }

    /* Transport centré ; le slider et le compteur passent chacun
       sur leur propre ligne, pleine largeur. */
    .row.transport { justify-content: center; gap: .75rem; }
    .row.transport .ctrl { min-width: 48px; height: 48px; }
    .row.transport .ctrl.play { min-width: 64px; }
    .row.transport .timeline { flex: 1 1 100%; order: 4; min-width: 0; }
    .row.transport .pos { flex: 1 1 100%; text-align: center; margin-left: 0; order: 5; }

    /* Vitesses : grille 4 colonnes égales ; Boucle et « Revenir au
       direct » pleine largeur en dessous. */
    .row.opts { display: grid; grid-template-columns: repeat(4, 1fr); gap: .5rem; }
    .row.opts .lbl, .row.opts .spacer { display: none; }
    .row.opts .speeds { display: contents; }
    .row.opts .speed { width: 100%; height: auto; min-height: 44px; padding: .4rem; }
    #tl-loop { grid-column: 1 / -1; }
    .row.opts .btn { grid-column: 1 / -1; justify-content: center; min-height: 44px; }

    /* Slider : pouce agrandi pour le tactile. */
    .timeline { -webkit-appearance: none; appearance: none; height: 28px; background: transparent; }
    .timeline::-webkit-slider-runnable-track { height: 6px; border-radius: 3px; background: var(--bordure); }
    .timeline::-webkit-slider-thumb {
      -webkit-appearance: none;
      width: 24px; height: 24px; margin-top: -9px;
      border-radius: 50%; background: var(--bleu); border: none;
    }
    .timeline::-moz-range-track { height: 6px; border-radius: 3px; background: var(--bordure); }
    .timeline::-moz-range-thumb { width: 24px; height: 24px; border-radius: 50%; background: var(--bleu); border: none; }

    .api button { width: 44px; height: 44px; }

    /* Libellé court : « Télécharger l'image » wrappe en demi-largeur. */
    .actions .btn .long { display: none; }
  }
</style>
</head>
<body data-mode="live">

<header>
  <img class="logo" src="logo.png" alt="<?= $h(SITE_LOGO_ALT) ?>">
  <h1><?= $h(SITE_HEADING) ?></h1>
</header>

<main>
  <div class="tabs" role="tablist">
    <button class="tab active" id="tab-live" role="tab"><span class="tabdot" aria-hidden="true"></span>Live</button>
    <button class="tab" id="tab-timelapse" role="tab"><svg class="ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>Timelapse (<?= $h($windowLabel) ?>)</button>
  </div>

  <div class="viewer">
    <div class="frame" id="frame">
      <img id="photo" src="latest.php" alt="Image de la webcam">
      <div class="msg" id="msg">Image indisponible pour le moment…</div>
    </div>
    <div class="meta">
      <span class="dot only-live" id="livedot" title="Mise à jour automatique"></span>
      <span class="stamp" id="stamp">—</span>
      <span class="only-live" id="ago"></span>
      <span class="only-timelapse pos" id="pos"></span>
    </div>
  </div>

  <!-- Avertissement : la dernière image dépasse WARN_STALE_S secondes -->
  <div class="warn" id="warn">
    <svg class="ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
    <span id="warn-text"></span>
  </div>

  <!-- Actions mode Live -->
  <div class="actions only-live">
    <button class="btn" id="refresh"><svg class="ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.65 6.35A7.96 7.96 0 0 0 12 4a8 8 0 1 0 7.73 10h-2.08A6 6 0 1 1 12 6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>Rafraîchir</button>
    <a class="btn accent" id="download" href="latest.php" download><svg class="ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></svg>Télécharger<span class="long">&nbsp;l'image</span></a>
  </div>

  <!-- Contrôles mode Timelapse -->
  <div class="controls only-timelapse">
    <div class="row transport">
      <button class="ctrl" id="tl-prev" title="Image précédente" aria-label="Image précédente"><svg class="ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 6h2v12H6zM18 6v12l-9-6z"/></svg></button>
      <button class="ctrl play" id="tl-play" title="Lecture / Pause" aria-label="Lecture / Pause">
        <svg class="ico ico-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
        <svg class="ico ico-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
      </button>
      <button class="ctrl" id="tl-next" title="Image suivante" aria-label="Image suivante"><svg class="ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 6h2v12h-2zM6 6v12l9-6z"/></svg></button>
      <input class="timeline" id="tl-slider" type="range" min="0" max="0" value="0" step="1">
      <span class="pos" id="tl-counter">0 / 0</span>
    </div>
    <div class="row opts">
      <span class="lbl">Vitesse</span>
      <span class="speeds" id="tl-speeds">
        <button class="speed" data-x="1">×1</button>
        <button class="speed active" data-x="2">×2</button>
        <button class="speed" data-x="4">×4</button>
        <button class="speed" data-x="8">×8</button>
      </span>
      <button class="speed" id="tl-loop" title="À la fin : rejouer en boucle au lieu de revenir au direct"><svg class="ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z"/></svg>Boucle</button>
      <span class="spacer"></span>
      <button class="btn secondary" id="tl-live"><svg class="ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>Revenir au direct</button>
    </div>
  </div>

  <div class="api only-live">
    <span class="api-label">URL stable de la dernière image (pour applis)
      <span class="api-status" id="copy-status" aria-live="polite"></span></span>
    <div class="api-row">
      <code id="apiurl">latest.php</code>
      <button id="copy" type="button" title="Copier l'URL" aria-label="Copier l'URL">
        <svg class="ico ico-copy" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11v14z"/></svg>
        <svg class="ico ico-check" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
      </button>
    </div>
  </div>
</main>

<footer><?= $h(SITE_FOOTER) ?></footer>

<script>
(() => {
  "use strict";

  // Réglages injectés depuis config.php (voir config.example.php).
  const REFRESH_MS    = <?= json_encode(REFRESH_MS) ?>;   // cadence de l'image live
  const LIST_MS       = <?= json_encode(LIST_MS) ?>;   // cadence de la liste (timelapse)
  const BASE_FPS      = <?= json_encode(BASE_FPS) ?>;       // images/s à ×1 (×2/×4/×8 multiplient)
  const WARN_STALE_S  = <?= json_encode(WARN_STALE_S) ?>;     // seuil d'alerte image périmée
  const WINDOW_LABEL  = <?= json_encode($windowLabel) ?>; // libellé de la fenêtre timelapse

  const body   = document.body;
  const photo  = document.getElementById('photo');
  const frame  = document.getElementById('frame');
  const stamp  = document.getElementById('stamp');
  const agoEl  = document.getElementById('ago');
  const apiurl = document.getElementById('apiurl');

  // URL « jolie » affichée aux applis tierces : latest.jpg, réécrite vers
  // latest.php par .htaccess en prod (Apache). En interne la page appelle
  // toujours latest.php, qui marche partout — y compris en dev sans Apache.
  const absLatest = new URL('latest.jpg', location.href).href;
  apiurl.textContent = absLatest;

  let lastTs = null;          // epoch de la dernière image connue
  let images = [];            // liste timelapse [{url, ts, time}], ancien → récent
  let serverSkew = 0;         // (serveur now) - (local now), pour estimer l'heure serveur

  const warnEl  = document.getElementById('warn');
  const warnTxt = document.getElementById('warn-text');

  // Heure serveur estimée (epoch s), robuste à une horloge client décalée.
  const serverNow = () => Math.floor(Date.now() / 1000) + serverSkew;

  // --- Préférences timelapse persistées (localStorage) ---
  // localStorage peut être indisponible (navigation privée stricte) : tout
  // accès passe par loadPref/savePref qui dégradent en silence.
  const SPEEDS = [1, 2, 4, 8];
  function loadPref(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }
  function savePref(key, val) {
    try { localStorage.setItem(key, val); } catch (e) { /* stockage indisponible */ }
  }

  // --- Timelapse state ---
  let idx = 0;                // index courant dans images[]
  let playing = false;
  let speed = 2;              // défaut ×2 ; valeur stockée validée contre SPEEDS
  let loop = false;           // fin de séquence : boucle (true) ou retour Live
  let playTimer = null;

  function fmt(ts) {
    return new Date(ts * 1000).toLocaleString('fr-FR', {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
  }
  const mode = () => body.dataset.mode;

  // ---- Récupération de la liste (partagée live + timelapse) ----
  async function fetchList() {
    try {
      const r = await fetch('images.php', { cache: 'no-store' });
      const data = await r.json();
      images = data.images || [];
      if (typeof data.now === 'number') {
        serverSkew = data.now - Math.floor(Date.now() / 1000);
      }
      // last_ts reflète la dernière image même si la liste est tronquée.
      lastTs = data.last_ts ?? (images.length ? images[images.length - 1].ts : null);
      updateWarn();
      return true;
    } catch (e) {
      return false;
    }
  }

  // Bandeau d'alerte si la dernière image est trop vieille (cam HS ?).
  // Visible dans les deux modes (live et timelapse).
  function updateWarn() {
    if (lastTs === null) { warnEl.classList.remove('show'); return; }
    const age = serverNow() - lastTs;
    if (age > WARN_STALE_S) {
      const mn = Math.floor(age / 60);
      warnTxt.textContent =
        `Dernière image il y a ${mn} min (${fmt(lastTs)}) — `
        + `la caméra ne répond peut-être plus.`;
      warnEl.classList.add('show');
    } else {
      warnEl.classList.remove('show');
    }
  }

  // =================== MODE LIVE ===================
  async function refreshLive() {
    photo.src = 'latest.php?t=' + Date.now();
    await fetchList();
    if (lastTs !== null) stamp.textContent = fmt(lastTs);
  }

  function tickAgo() {
    updateWarn(); // réévalue la fraîcheur chaque seconde, dans les deux modes
    if (mode() !== 'live' || lastTs === null) { agoEl.textContent = ''; return; }
    const s = Math.max(0, serverNow() - lastTs);
    agoEl.textContent = s < 60 ? `· il y a ${s}s` : `· il y a ${Math.floor(s/60)} min`;
  }

  // =================== MODE TIMELAPSE ===================
  function showFrame(i) {
    if (!images.length) {
      stamp.textContent = 'Aucune image sur les dernières ' + WINDOW_LABEL;
      document.getElementById('pos').textContent = '';
      document.getElementById('tl-counter').textContent = '0 / 0';
      return;
    }
    idx = Math.max(0, Math.min(i, images.length - 1));
    const im = images[idx];
    photo.src = im.url;
    stamp.textContent = fmt(im.ts);
    document.getElementById('pos').textContent = `image ${idx + 1}/${images.length}`;
    document.getElementById('tl-counter').textContent = `${idx + 1} / ${images.length}`;
    const slider = document.getElementById('tl-slider');
    slider.max = String(images.length - 1);
    slider.value = String(idx);
  }

  function setPlaying(on) {
    playing = on;
    document.getElementById('tl-play').classList.toggle('playing', on);
    if (playTimer) { clearInterval(playTimer); playTimer = null; }
    if (on) {
      // Plancher 30 ms (~33 img/s) : laisse passer ×8 (BASE_FPS 4 → 32 img/s)
      // sans le brider, tout en bornant la charge navigateur.
      const period = Math.max(30, 1000 / (BASE_FPS * speed));
      playTimer = setInterval(stepAuto, period);
    }
  }

  // Avance auto ; à la fin → boucle si activée, sinon bascule en mode Live.
  function stepAuto() {
    if (idx >= images.length - 1) {
      if (loop && images.length > 1) { showFrame(0); return; }
      setPlaying(false);
      enterLive();
      return;
    }
    showFrame(idx + 1);
  }

  function setSpeed(x) {
    speed = x;
    document.querySelectorAll('#tl-speeds .speed').forEach(b =>
      b.classList.toggle('active', Number(b.dataset.x) === x));
    savePref('tl-speed', String(x));
    if (playing) setPlaying(true); // recalcule la période
  }

  function setLoop(on) {
    loop = on;
    document.getElementById('tl-loop').classList.toggle('active', on);
    savePref('tl-loop', on ? '1' : '0');
  }

  // ---- Bascule de mode ----
  async function enterLive() {
    setPlaying(false);
    body.dataset.mode = 'live';
    document.getElementById('tab-live').classList.add('active');
    document.getElementById('tab-timelapse').classList.remove('active');
    await refreshLive();
  }

  async function enterTimelapse() {
    body.dataset.mode = 'timelapse';
    document.getElementById('tab-timelapse').classList.add('active');
    document.getElementById('tab-live').classList.remove('active');
    const ok = await fetchList();
    showFrame(0);
    if (ok && images.length > 1) setPlaying(true);
  }

  // Rattrapage timelapse : recharge la liste périodiquement. Si on est à la
  // fin, on suit les nouvelles images ; sinon on garde la position.
  async function refreshTimelapseList() {
    const atEnd = idx >= images.length - 1;
    const prevLen = images.length;
    await fetchList();
    if (atEnd && images.length > prevLen) showFrame(images.length - 1);
    else showFrame(idx); // ré-applique bornes/slider si la liste a bougé
  }

  // ---- Câblage ----
  photo.addEventListener('load',  () => frame.classList.remove('error'));
  photo.addEventListener('error', () => frame.classList.add('error'));

  document.getElementById('tab-live').addEventListener('click', enterLive);
  document.getElementById('tab-timelapse').addEventListener('click', enterTimelapse);

  document.getElementById('refresh').addEventListener('click', refreshLive);

  document.getElementById('tl-play').addEventListener('click', () => setPlaying(!playing));
  document.getElementById('tl-prev').addEventListener('click', () => { setPlaying(false); showFrame(idx - 1); });
  document.getElementById('tl-next').addEventListener('click', () => { setPlaying(false); showFrame(idx + 1); });
  document.getElementById('tl-live').addEventListener('click', enterLive);
  document.getElementById('tl-slider').addEventListener('input', (e) => {
    setPlaying(false);
    showFrame(Number(e.target.value));
  });
  document.getElementById('tl-speeds').addEventListener('click', (e) => {
    const b = e.target.closest('.speed');
    if (b) setSpeed(Number(b.dataset.x));
  });
  document.getElementById('tl-loop').addEventListener('click', () => setLoop(!loop));

  // API clipboard absente hors contexte sécurisé (HTTP) et sur vieux
  // navigateurs : on cache le bouton plutôt que de le laisser mort.
  // L'URL reste copiable à la main (user-select: all sur le <code>).
  const copyBtn    = document.getElementById('copy');
  const copyStatus = document.getElementById('copy-status');
  if (!navigator.clipboard || !navigator.clipboard.writeText) {
    copyBtn.hidden = true;
  }
  let copyTimer = null;
  copyBtn.addEventListener('click', async () => {
    let ok = true;
    try { await navigator.clipboard.writeText(absLatest); }
    catch (e) { ok = false; }
    copyBtn.classList.toggle('ok', ok);
    copyStatus.textContent = ok ? 'Copié ✓' : 'Échec de la copie';
    copyStatus.classList.toggle('err', !ok);
    if (copyTimer) clearTimeout(copyTimer);
    copyTimer = setTimeout(() => {
      copyBtn.classList.remove('ok');
      copyStatus.textContent = '';
    }, 1500);
  });

  // ---- Démarrage ----
  // Restaure les préférences timelapse de la visite précédente (valeurs
  // validées : vitesse contre SPEEDS, boucle ramenée à un booléen).
  const storedSpeed = Number(loadPref('tl-speed'));
  if (SPEEDS.includes(storedSpeed)) speed = storedSpeed;
  setSpeed(speed);
  setLoop(loadPref('tl-loop') === '1');

  // Deep-link optionnel : ?mode=timelapse ouvre directement le timelapse.
  if (new URLSearchParams(location.search).get('mode') === 'timelapse') {
    enterTimelapse();
  } else {
    refreshLive();
  }
  // Deux cadences distinctes, comme annoncé en config : REFRESH_MS pilote
  // l'image live (rechargée avec son horodatage, les deux vont ensemble),
  // LIST_MS le rattrapage de la liste du timelapse. Chaque timer ne fait rien
  // dans l'autre mode — inutile de solliciter le serveur pour une vue cachée.
  setInterval(() => { if (mode() === 'live') refreshLive(); }, REFRESH_MS);
  setInterval(() => { if (mode() === 'timelapse') refreshTimelapseList(); }, LIST_MS);
  setInterval(tickAgo, 1000);
})();
</script>
</body>
</html>
