# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

Page webcam publique. Une caméra pousse des snapshots JPEG par FTP dans
`snap/AAAA/MM/JJ/<CAM_PREFIX>_<canal>_AAAAMMJJhhmmss.jpg`, sous-dossier à
côté de l'app PHP. Déployable à la racine d'un domaine ou dans un
sous-dossier (`RewriteBase` du `.htaccess`).

Contraintes dures : PHP 8.x sans Composer ni dépendance, JS/CSS vanilla
inline (zéro build), **pas de cron** (tout traitement se fait à la volée
dans les requêtes). Docs : `README.md` (installation), `doc/PRD.md`
(produit), `doc/TECHNIQUE.md` (architecture, contrats API),
`doc/SECURITE.md` (revue sécurité), `doc/DEPLOIEMENT.md` (checklist prod).

Configuration : `config.php` (local, non versionné) avec fallback sur
`config.example.php`. Toute valeur d'instance (préfixe caméra, titres,
URL, fenêtre/rétention, CORS) y vit — ne pas la remettre en dur.

## Commandes

```bash
# Serveur de dev (la réécriture latest.jpg -> latest.php n'existe qu'en prod
# via Apache/.htaccess ; en dev, utiliser latest.php directement)
php -S 127.0.0.1:8000

# Lint
php -l lib.php && php -l images.php && php -l latest.php && php -l index.php

# .htaccess sur un vrai Apache (docroot jetable, fichiers hostiles dans snap/,
# nominal + sans mod_headers + sans mod_rewrite). Sortie 0 si tout passe.
tools/test-htaccess.sh

# Site de documentation (npm N'EXISTE QUE ici ; jamais à la racine)
npm --prefix site install
./site/build-local.sh --serve   # construit _site/, sert sur :8080
```

Pas de build. Vérification = serveur dev + curl (`images.php` → JSON,
`latest.php` → image, en-têtes via `curl -I`), plus `tools/test-htaccess.sh`
dès qu'on touche au `.htaccess` — c'est le seul test automatisé du projet, et
`php -S` ne peut structurellement pas le remplacer.
Les dossiers `snap/2026/...` sont des données de test locales (ignorés
par git).

## Architecture

- `lib.php` — fonctions partagées. `snap_root()` = `__DIR__ . '/snap'`
  (même layout dev et prod). Parcours de l'arbre `AAAA/MM/JJ` via
  `scan_dirs()`/`list_day_dir()`.
- `latest.php` — streame la dernière image (URL stable pour applis
  tierces, exposée en `latest.jpg` via `.htaccess`). Anti-cache + garde-fou
  `realpath` sous `snap_root()`.
- `images.php` — JSON de la fenêtre courante (source du timelapse) **et**
  cleanup paresseux : supprime images au-delà de `RETENTION_SECONDS` +
  dossiers vides, throttlé à 1 passage/min (endpoint public).
- `config.example.php` — toutes les constantes de l'instance ; `lib.php`
  charge `config.php` s'il existe, sinon ce fichier.
- `index.php` — page unique, deux modes (Live / Timelapse) pilotés en JS,
  en-têtes de sécurité (CSP same-origin, inline autorisé).
- `site/` — vitrine HTML/CSS écrite à la main (`/`) + documentation VitePress
  (`/doc/`), publiées sur https://airfield-cam.ratelet.fr par
  `.github/workflows/pages.yml`. **Seul endroit du dépôt avec npm et un
  build** ; jamais déployé sur l'hébergement. Les Markdown ne bougent pas :
  VitePress les lit sur place (`srcDir: '..'`), donc **aucune duplication de
  contenu** — ne jamais recopier un document dans `site/`. Le build échoue sur
  lien mort : renommer un `.md` casse la publication. Les liens vers du code
  (non publié) s'écrivent en URL absolues GitHub.

Invariants à ne pas casser :

- **L'heure d'une image vient de son nom de fichier** (`ts_from_name`),
  jamais de `filemtime` (faussé par l'upload FTP).
- **Deux horloges, jamais mélangées.** L'ancre du cleanup vient de
  l'horloge caméra (le nom de fichier) ; la purge des fichiers dont
  l'horodatage est inexploitable vient de `filemtime`, donc de l'horloge
  serveur. Croiser les deux seuils supprimerait trop tôt ou jamais.
- **Le verrou de throttle n'est pas une condition de correction** : s'il ne
  peut pas être écrit, le cleanup a lieu quand même (dégradé). Sans cron,
  le sauter désactive la rétention en silence.
- **Cleanup et fenêtre timelapse ancrés sur la dernière image**, pas sur
  `time()` : si la caméra s'arrête, la dernière fenêtre connue reste
  rejouable, jamais effacée. Mais l'ancre est **bornée à l'heure courante**
  (`anchor_ts`) : sans cela, une horloge caméra en avance ferait effacer
  tout l'historique. Ne pas retirer ce `min`.
- La fenêtre effective est `min(WINDOW_SECONDS, RETENTION_SECONDS)`
  (`EFFECTIVE_WINDOW_SECONDS`) : on n'annonce jamais une durée que la
  rétention a déjà supprimée.
- Les URLs du JSON (`web_path`) sont **relatives** à la page
  (`snap/2026/06/11/x.jpg`).
- Tout chemin manipulé reste confiné à `snap_root()` ; aucune entrée
  utilisateur ne touche le système de fichiers.

## Sécurité — pièges principaux

Le sous-dossier `snap/` est la cible des uploads FTP de la caméra. Le
`.htaccess` racine (unique) combine : liste blanche PHP
(`index|latest|images.php`), liste blanche `snap/` (**seul le chemin
complet `AAAA/MM/JJ/<préfixe>_<canal>_<horodatage>.jpg` est servi**) +
règle rewrite `[F]` refusant tout PHP — extensions alternatives
(`.phtml`, `.phar`, `.pht`, `.phpN`) et casse comprises, car le mapping
handler Apache est insensible à la casse. La règle `[F]` est
indispensable car `FilesMatch` matche par nom de fichier, pas par
chemin : sans elle, un `index.php` déposé dans `snap/` serait exécuté.

La liste blanche `snap/` porte sur le nom complet et non sur le seul
suffixe `.jpg` : un `evil.php.jpg` passerait sinon, et un serveur en
`AddHandler application/x-httpd-php .php` l'exécuterait (le mapping
handler ne regarde pas que la dernière extension).

Conséquences : un nouveau point d'entrée PHP doit être ajouté à la liste
blanche, sinon 403 en prod ; ne jamais mettre de code dans `snap/` ; et
**ne pas réordonner les règles rewrite** (passthrough `[L]` des entrées
légitimes avant les règles `[F]`). `php -S` ignore le `.htaccess` → ces
règles ne se vérifient qu'en prod (checklist dans `doc/DEPLOIEMENT.md` §2).

`config.php` contient la config de l'instance : il ne doit **jamais** être
ajouté à la liste blanche des points d'entrée.
