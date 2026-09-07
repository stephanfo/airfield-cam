# Revue sécurité

**Date de revue initiale :** 2026-06-11
**Périmètre :** `index.php`, `latest.php`, `images.php`, `lib.php`,
`config.php`, `.htaccess`.

Ce document explique **pourquoi** les protections sont ce qu'elles sont.
La procédure de vérification en production est dans
[DEPLOIEMENT.md](DEPLOIEMENT.md) ; le signalement de vulnérabilité dans
[SECURITY.md](../SECURITY.md).

Contexte de menace : page publique sans authentification (choix assumé,
images publiques) ; le sous-dossier `snap/` reçoit les uploads FTP de la
caméra. Le risque dominant n'est pas le code PHP (aucune entrée
utilisateur) mais le **canal d'écriture FTP pointé dans l'espace web**.

---

## 1. Éléments vérifiés (déjà sains avant la revue)

| Élément | Constat |
|---------|---------|
| Entrées utilisateur | Aucun paramètre GET/POST utilisé côté serveur — pas d'injection possible (chemins, SQL, commandes) |
| Traversée de chemin | Scan confiné à `snap_root()` ; noms de fichiers filtrés par regex stricte `^<CAM_PREFIX>_\d+_\d{14}\.jpg$`, ancrée et construite avec `preg_quote` (un préfixe contenant des métacaractères ne peut pas élargir le motif) |
| Destruction de l'archive par nom de fichier | L'heure venant du nom, un fichier daté dans le futur deviendrait l'ancre du cleanup et emporterait tout l'historique. `ts_from_name` refuse les dates impossibles et les horodatages à plus de 24 h dans le futur ; `anchor_ts` borne l'ancre à l'heure courante, ce qui rend la perte impossible quelle que soit la confiance accordée aux noms |
| Garde-fou `latest.php` | `realpath()` + vérif. préfixe : le fichier servi est forcément sous `snap_root()` |
| XSS | Aucune donnée non maîtrisée injectée dans le HTML ; le JSON est consommé via `img.src` (pas de `innerHTML`) |
| 404 / erreurs | Messages propres en `text/plain`, pas de fuite de chemins |
| Borne anti-abus | `images.php` limite la liste à 600 entrées (`MAX_IMAGES`) |

## 2. Failles corrigées pendant la revue

### 2.1 🔴 Exécution PHP possible dans le dossier d'upload FTP → risque RCE

Un attaquant disposant des identifiants FTP de la caméra pouvait déposer
un `shell.php` et l'exécuter via le navigateur (compromission serveur).

**Correctif** (`.htaccess` racine unique, hors de portée du compte FTP
chrooté sur `snap/`) :
- Liste blanche : tout PHP refusé sauf `index.php`, `latest.php`,
  `images.php` ; `lib.php`, `*.md`, `.gitignore` non servis. Les motifs
  couvrent les **extensions alternatives mappées sur PHP** (`.phtml`,
  `.phar`, `.pht`, `.php5`/`.php7`…) et sont **insensibles à la casse**
  (`(?i)` / `[NC]`) — le mapping handler Apache l'est aussi : sans ça,
  un `shell.PhP` ou `shell.phtml` passait.
- **Liste blanche `snap/`** : `RewriteCond %{REQUEST_URI}
  !^.*/snap/\d{4}/\d{2}/\d{2}/[A-Za-z0-9_-]+_\d+_\d{14}\.jpg$ [NC]`
  + `RewriteRule ^snap/. - [F,L]` — sous la zone d'écriture caméra, seul un
  chemin conforme au contrat de nommage est servi ; tout le reste est 403,
  quelle que soit l'extension (`.shtml`, `.cgi`, futurs handlers…).

  Le filtre porte sur le **chemin complet** et non sur le seul suffixe
  `.jpg`. Un `!\.jpg$` laisserait passer `evil.php.jpg` : la règle `[F]`
  générique ci-dessous est ancrée en fin de nom et ne l'attrape pas, et un
  serveur configuré en `AddHandler application/x-httpd-php .php` — encore
  courant en mutualisé — mappe sur PHP dès qu'une extension `.php` apparaît
  dans le nom, pas seulement en dernière position. Le fichier serait alors
  exécuté malgré son suffixe `.jpg`. Ce filtrage par chemin ferme aussi les
  dépôts à plat dans `snap/`, hors arborescence `AAAA/MM/JJ`.
- Règle générique `RewriteRule \.(php\d*|phtml|phar|pht)(/.*)?$ - [F,L,NC]`
  pour tout le site, PATH_INFO inclus — nécessaire car la liste blanche
  `FilesMatch` matche le nom de fichier sans le chemin : un fichier nommé
  `index.php` déposé par FTP dans `snap/` serait sinon exécuté.
- `RewriteRule ^\.git - [F,L]` : protège `.git/` si le dépôt est un jour
  déployé tel quel (sinon `/.git/HEAD` permet de reconstruire les
  sources).
- ⚠️ Cette protection dépend de mod_rewrite et de l'**ordre des règles**
  — ne pas réordonner (les passthrough `[L]` des entrées légitimes
  doivent précéder la règle `[F]` générique).

### 2.2 🟠 Cleanup (suppression de fichiers) déclenchable à volonté

`images.php` est public et chaque hit lançait `scandir` + `unlink`.

**Correctif** : throttle à 1 passage/min (fichier sentinelle dans le tmp
système).

Le throttle est délibérément *best effort* : si la sentinelle ne peut pas
être écrite (tmp non inscriptible, `open_basedir`), le ménage a quand même
lieu, à chaque requête. Conditionner le ménage à l'écriture du verrou
revenait à désactiver la rétention en silence sur ces hébergements — et
sans cron, plus rien ne supprime. Un `scandir` non throttlé sur un arbre
d'un à deux dossiers jour est le moindre des deux maux.

### 2.3 🟠 Aucun en-tête de sécurité

**Correctif** :
- `index.php` : CSP stricte (`default-src 'none'`, tout same-origin,
  inline JS/CSS autorisé — contrainte du « zéro build »),
  `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`.
- `latest.php`, `images.php`, `index.php` : `X-Content-Type-Options:
  nosniff` (un faux `.jpg` déposé par FTP ne peut pas être réinterprété
  en HTML par le navigateur).

### 2.4 🟡 Listing des répertoires

Les dossiers `snap/AAAA/MM/JJ/` étaient énumérables.
**Correctif** : `Options -Indexes`.

### 2.5 🟡 Fallback de `snap_root()` surprenant

En cas de chemin prod absent, le code retombait silencieusement sur le
dossier du code via `DOCUMENT_ROOT`. **Correctif** : `snap_root()` =
`__DIR__` (app et images colocalisées, comportement unique dev/prod).

### Corrections connexes (robustesse, pas sécurité)

- `web_path()` : URLs relatives correctes (le timelapse était cassé, le
  préfixe d'installation se retrouvant dupliqué dans les URLs).
- `cleanup_old()` : balaie tout l'arbre `AAAA/MM/JJ` (plus seulement
  J/J-1) et supprime les dossiers vides — plus d'accumulation après une
  coupure caméra de plusieurs jours.
- `latest_image()` : plus de limite à 7 jours (parcours descendant de
  l'arbre).
- Cache `Cache-Control: public, max-age=86400, immutable` sur les
  snapshots (noms horodatés → immuables).

## 3. Tests effectués (local)

- `tools/test-htaccess.sh` : le `.htaccess` réel sur un Apache local, avec
  fichiers hostiles déposés dans `snap/`, dans les trois configurations de
  modules du tableau ci-dessus. Vérifié capable d'échouer : il attrape le
  bloc rewrite enveloppé dans `<IfModule>`, les règles réordonnées, un point
  d'entrée retiré de la liste blanche, et la liste blanche `snap/` relâchée
  en simple suffixe `.jpg`.
- `php -l` sur tous les fichiers modifiés.
- `images.php` : JSON valide, URLs relatives résolvables (HTTP 200),
  `now`/`last_ts`/`count` cohérents.
- `latest.php` / `latest.jpg` : 200, `Content-Type: image/jpeg`,
  anti-cache, `nosniff`, `Content-Disposition` horodaté.
- `index.php` : tous les en-têtes de sécurité présents (CSP, XFO,
  nosniff, Referrer-Policy).
- Cleanup end-to-end : image antidatée (J-3) supprimée au passage
  suivant, dossier jour vide purgé, images récentes intactes, throttle
  observé (passage sauté si < 60 s).

### Comportement en cas de module Apache manquant

Mesuré sur Apache 2.4 en local (docroot jetable, `.htaccess` réel, fichiers
hostiles déposés dans `snap/`) :

| Configuration | Résultat |
|---|---|
| `mod_rewrite` + `mod_headers` | nominal : seul le `.jpg` du contrat de nommage est servi, tout le reste de `snap/` en 403 |
| `mod_headers` absent | **identique** côté autorisations ; seuls les en-têtes de cache disparaissent |
| `mod_rewrite` absent | 500 sur tout le site — fermé, et nommé dans le log Apache |
| `mod_rewrite` absent **et bloc rewrite enveloppé dans `<IfModule>`** | `snap/…/index.php`, `evil.php.jpg` et `notes.txt` servis en 200 — c'est pourquoi ce bloc n'est délibérément pas enveloppé |

## 4. Vérification en production

⚠️ **Le `.htaccess` n'est pas testable en local** : `php -S` l'ignore
totalement. Les protections décrites en §2.1 ne peuvent être confirmées
qu'une fois en ligne.

La checklist à dérouler après chaque déploiement — y compris le dépôt
d'un faux `shell.php` par FTP pour vérifier qu'il n'est pas exécuté — est
dans **[DEPLOIEMENT.md §2](DEPLOIEMENT.md)**.

## 5. Hors périmètre du code — à traiter côté infrastructure

Le code ne peut rien contre un compte FTP compromis ou une caméra exposée
sur Internet. Ces points relèvent du déploiement et sont détaillés dans
**[DEPLOIEMENT.md §4](DEPLOIEMENT.md)** : compte FTP dédié et chrooté,
FTPS, durcissement de la caméra, HTTPS, surveillance externe.

Le plus important, s'il ne fallait en retenir qu'un : **le compte FTP de
la caméra doit être dédié et restreint au seul dossier `snap/`**. C'est la
porte d'entrée du risque résiduel de ce projet.

## 6. Risques résiduels acceptés

- CSP avec `unsafe-inline` (JS/CSS inline, contrainte « zéro build ») —
  valeur limitée mais cohérente ; pas de vecteur d'injection connu.
- Contenu des `.jpg` non validé comme JPEG réel (un faux `.jpg` est servi
  tel quel) — atténué par `nosniff` + `Content-Type: image/jpeg` forcé.
- Pas de protection DoS applicative au-delà du throttle et de
  `MAX_IMAGES` — relève de l'hébergeur.
- Endpoints publics sans authentification — choix produit assumé.
