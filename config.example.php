<?php
/**
 * config.example.php — configuration de référence.
 *
 * Pour personnaliser une instance : copier ce fichier en `config.php` et
 * l'adapter. `config.php` n'est pas versionné ; s'il est absent, c'est ce
 * fichier qui est chargé, et le projet tourne tel quel (utile en dev).
 *
 *     cp config.example.php config.php
 *
 * Attention : `RewriteBase` et les `ErrorDocument` du `.htaccess` ne sont
 * PAS pilotés par ce fichier — Apache ne lit pas la config PHP. Voir
 * doc/DEPLOIEMENT.md.
 *
 * Toutes les constantes de ce fichier sont obligatoires, et leur forme est
 * vérifiée au démarrage : `lib.php` refuse de démarrer si l'une manque ou
 * n'a pas le type attendu, en la nommant (voir CONFIG_SPEC). Après une mise
 * à jour, comparer son `config.php` à ce fichier.
 *
 * Copyright (C) 2026 Stephanfo
 *
 * Ce programme est un logiciel libre : vous pouvez le redistribuer et/ou le
 * modifier selon les termes de la GNU Affero General Public License version 3,
 * telle que publiée par la Free Software Foundation. Distribué sans aucune
 * garantie — voir le fichier LICENSE.
 */

declare(strict_types=1);

// --- Caméra ---------------------------------------------------------------

/**
 * Préfixe des fichiers poussés par la caméra. Le contrat de nommage est :
 *
 *     <CAM_PREFIX>_<canal>_AAAAMMJJhhmmss.jpg      ex. cam_01_20260611193004.jpg
 *
 * C'est de ce nom qu'est extraite l'heure de l'image (jamais de filemtime,
 * faussé par l'upload FTP). Un fichier qui ne matche pas est ignoré : si
 * rien ne s'affiche, c'est le premier point à vérifier.
 *
 * Caractères autorisés : lettres, chiffres, tiret et underscore — contrainte
 * vérifiée au démarrage. Ce n'est pas cosmétique : le `.htaccess` ne sert que
 * les noms de cette forme, donc un préfixe hors de ce jeu donnerait une page
 * dont Apache refuse chaque image (403), sans que le serveur de dev — qui ne
 * lit pas le `.htaccess` — ne le montre.
 *
 * Changer ce préfixe sur une instance déjà en service rend les anciennes
 * images invisibles — et le cleanup ne les supprimera plus, puisqu'il ne
 * touche que ce qu'il sait lire. Les retirer de `snap/` à la main.
 */
const CAM_PREFIX = 'cam';

/** Fuseau utilisé pour interpréter et afficher les horodatages. */
const SITE_TIMEZONE = 'Europe/Paris';

// --- Identité du site -----------------------------------------------------

const SITE_TITLE       = 'Webcam';
const SITE_HEADING     = 'Webcam';
const SITE_LOGO_ALT    = 'Logo';
const SITE_FOOTER      = 'Image rafraîchie automatiquement';
const SITE_DESCRIPTION = 'Image en direct et timelapse de la dernière heure.';

/**
 * URL publique de l'app, slash final inclus. Sert uniquement à construire
 * les balises Open Graph (aperçu de lien WhatsApp/iMessage/réseaux
 * sociaux), qui exigent des URLs absolues : les crawlers ne résolvent pas
 * les chemins relatifs. Tout le reste de l'app fonctionne en relatif.
 *
 * Laisser vide ('') pour ne pas émettre de balises Open Graph — c'est le
 * défaut, pour qu'une instance non configurée n'annonce pas une URL fausse.
 */
const SITE_BASE_URL = '';

/** Description courte pour l'aperçu de lien (Open Graph). */
const SITE_OG_DESCRIPTION = 'Image en direct et timelapse de la dernière heure.';

/** Image d'aperçu (1200×630 recommandé), relative à SITE_BASE_URL. */
const SITE_OG_IMAGE = 'og-image.jpg';

/** Couleur de thème (barre d'adresse mobile) et teintes de l'interface. */
const THEME_COLOR  = '#045BCB';
const THEME_ACCENT = '#AECD47';

// --- Partage inter-origines (CORS) ---------------------------------------

/**
 * Origines autorisées à lire `images.php` et `latest.php` depuis un autre
 * domaine (lecture seule, données publiques non sensibles). Laisser `[]` si
 * l'app est consommée uniquement par sa propre page — c'est le cas par défaut.
 *
 * Inutile pour afficher l'image dans une balise `<img>` : celle-ci n'est
 * jamais soumise au CORS. Ne sert qu'aux consommateurs en fetch()/XHR.
 *
 * Les `url` du JSON sont relatives à la racine de l'app : un consommateur
 * d'une autre origine doit les préfixer lui-même par l'URL de l'app.
 *
 * Comparaison stricte, sans joker : indiquer le schéma et le port exact,
 * ex. 'https://exemple.fr', 'http://localhost:9200'.
 */
const CORS_ALLOWED_ORIGINS = [];

// --- Fenêtre et rétention -------------------------------------------------

/**
 * Deux réglages indépendants — mais dans un seul sens : on ne peut pas
 * rejouer ce que le cleanup a déjà supprimé.
 *
 *   RETENTION >= WINDOW  → la fenêtre configurée est utilisée
 *   RETENTION <  WINDOW  → la fenêtre est ramenée à la rétention
 *
 * Le second cas n'est pas une erreur : l'app borne la fenêtre et affiche la
 * durée réellement disponible, plutôt que d'annoncer 2 h et n'en montrer
 * qu'une. Pour un timelapse de 2 h, passer les DEUX à 7200.
 *
 * Il n'y a pas de cron : le ménage se fait à la volée dans `images.php`,
 * throttlé à un passage par minute.
 */
const WINDOW_SECONDS    = 3600; // fenêtre rejouable en timelapse : 1 h
const RETENTION_SECONDS = 3600; // au-delà, les images sont supprimées

/**
 * Borne anti-abus sur le nombre d'images renvoyées par `images.php`
 * (endpoint public, contenu de snap/ alimenté par FTP) : la réponse reste
 * bornée même si le cleanup échoue ou si le dossier est rempli.
 *
 * En régime normal une caméra à 30 s produit ~120 images/h, soit 5 h de
 * marge. Au-delà, c'est cette borne — et non la rétention — qui tronque la
 * fenêtre : l'augmenter si WINDOW_SECONDS dépasse ~5 h.
 */
const MAX_IMAGES = 600;

// --- Interface (injecté en JS) -------------------------------------------

const REFRESH_MS   = 30000; // cadence de l'image live (aligner sur la caméra)
const LIST_MS      = 30000; // cadence de rechargement de la liste, en timelapse
const BASE_FPS     = 4;     // images/s à ×1 (×2/×4/×8 multiplient)
                            // ~120 img/h → ×1 rejoue l'heure en ~30 s
const WARN_STALE_S = 300;   // alerte si la dernière image dépasse cet âge
