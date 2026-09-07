# Documentation technique

**PRD (produit) :** voir [PRD.md](PRD.md)
**Déploiement :** voir [DEPLOIEMENT.md](DEPLOIEMENT.md)

Ce document couvre l'architecture, les contrats d'API et les contraintes
d'implémentation. Le « quoi » et le « pourquoi produit » sont dans le PRD.

---

## 1. Décisions techniques actées

| Sujet | Décision |
|-------|----------|
| Architecture | PHP + JS vanilla, zéro build, hébergement LAMP simple |
| Liste des images | Endpoint PHP renvoyant du JSON (URLs + timestamps) |
| Image stable | `latest.php` **streame** la dernière image (toujours frais) |
| Rétention | Conserver ≥ 1h. **Pas de cron** → cleanup paresseux dans `images.php` |
| Version PHP | **8.x** (prod + local 8.5) → syntaxe moderne autorisée |
| Intervalle cam | **~30 s** → ≈120 images/heure |
| Base path | App (PHP) et sous-dossier `snap/` colocalisés, à la racine ou dans un sous-dossier (`RewriteBase` du `.htaccess`) |
| Horodatage | **Parsé depuis le nom de fichier** (`<CAM_PREFIX>_<canal>_AAAAMMJJhhmmss`), fuseau `SITE_TIMEZONE` |
| Assets | Placeholders génériques régénérables (`tools/generate-assets.php`) |
| Configuration | `config.php` (local, non versionné), fallback sur `config.example.php` |

---

## 2. Architecture

```
Caméra ──FTP──> serveur:<base>/snap/AAAA/MM/JJ/<CAM_PREFIX>_<canal>_*.jpg
                                    │
                ┌───────────────────┼────────────────────┐
                │                   │                     │
          latest.php           images.php            cleanup (rétention)
        (image stable)      (liste JSON 1h)        (paresseux, dans images.php)
                │                   │
                └─────────┬─────────┘
                          │
                      index.php
              (HTML + CSS + JS vanilla)
```

---

## 3. Composants

### `index.php` — page principale
- Sert le HTML/CSS/JS.
- À l'ouverture : affiche la dernière image (via `latest.php`).
- JS pilote les deux modes (Live image / Timelapse), bascule sans recharger.

### `latest.php` — URL stable, endpoint qui streame
- Trouve la dernière image et la renvoie en flux
  (`readfile` + `Content-Type: image/jpeg`).
- Toujours frais : pas de cron, pas de copie. La vraie dernière image
  à chaque hit.
- Consommable directement par une appli tierce. Exposé en `latest.jpg`
  via `.htaccess` (mod_rewrite) : `latest.jpg` → `latest.php`. C'est l'URL
  « propre » affichée sur la page et destinée aux applis. La réécriture
  n'agit qu'en prod (Apache) ; en dev (`php -S`) on utilise `latest.php`.
- En-têtes anti-cache pour garantir la fraîcheur.
- Parcourt l'arbre `AAAA/MM/JJ` du plus récent au plus ancien et s'arrête au
  premier jour contenant une image valide : la dernière image reste
  retrouvable même après plusieurs jours de coupure caméra. En régime normal
  cela s'arrête sur le premier dossier ouvert.
- **CORS** : même liste blanche que `images.php` (inutile pour une balise
  `<img>`, nécessaire pour un `fetch()` cross-origin).

### `images.php` — endpoint JSON
- Scanne la fenêtre de la dernière heure.
- Renvoie un tableau ordonné (ancien → récent).
- Sert de source à la vue timelapse et au refresh de l'image courante.
- Déclenche le cleanup paresseux (voir §5).
- **CORS** : renvoie `Access-Control-Allow-Origin` (+ `Vary: Origin`) pour
  les origines de la liste blanche `CORS_ALLOWED_ORIGINS` (vide par défaut,
  voir `config.example.php`). Lecture seule, données non sensibles. La page
  elle-même reste same-origin et n'en dépend pas.

### Cleanup (rétention) — paresseux, pas de cron
- `images.php` supprime les images plus vieilles que 1h **par rapport à la
  dernière image disponible** (pas par rapport à l'heure courante).
- Conséquence : si la caméra s'arrête, on conserve la dernière heure
  d'images connue au lieu de vider le dossier → le timelapse reste rejouable
  après une coupure cam.
- La fenêtre renvoyée (`list_window`) est également ancrée sur la dernière
  image, pour la même raison.
- **L'ancre ne dépasse jamais l'heure courante** (`anchor_ts`). Sans cette
  borne, une caméra dont l'horloge est en avance produirait un seuil dans le
  futur et le cleanup viderait tout l'historique valide d'un coup. La borne
  n'a aucun effet dans le cas nominal (dernière image ≤ maintenant) et rend
  cette perte impossible par construction.
- En complément, `ts_from_name` refuse les dates impossibles et les
  horodatages à plus de 24 h dans le futur (`FUTURE_TOLERANCE_SECONDS`).
- Ne sont supprimés que les fichiers conformes au contrat de nommage. Après
  un changement de `CAM_PREFIX`, les anciennes images ne sont plus nettoyées
  et doivent être retirées à la main.
- Un fichier conforme au contrat mais dont l'horodatage est inexploitable
  (date impossible, ou horloge caméra perdue le datant dans le futur) est
  purgé sur `filemtime`, c'est-à-dire sur l'heure de réception FTP — donc sur
  l'horloge du **serveur**, jamais sur l'ancre, qui vient de l'horloge de la
  **caméra**. Sans ce second régime ces fichiers seraient indélébiles :
  invisibles à `list_day_dir`, donc jamais candidats à la suppression.
- Le cleanup n'est **pas** conditionné à l'écriture du verrou de throttle.
  Si le verrou ne peut pas être écrit, le ménage a lieu à chaque requête :
  dégradé, mais vivant. Sans cron, l'inverse revenait à désactiver la
  rétention en silence.
- Le cleanup a lieu même quand aucune image lisible n'existe (`anchor_ts`
  renvoie alors l'heure courante) : c'est précisément le cas où un dossier
  rempli d'horodatages inexploitables ne doit pas geler la rétention.
- Le ménage suppose que le compte du serveur web peut écrire dans `snap/`
  (voir doc/DEPLOIEMENT.md §1) : les erreurs de `unlink` sont silencieuses.
- La page rafraîchit ~toutes les 30s → dossier nettoyé tant qu'on regarde.

---

## 4. Contrats d'API

### `GET images.php`

Réponse `application/json` :

```json
{
  "now": 1749672244,
  "last_ts": 1749672184,
  "count": 120,
  "images": [
    {
      "url": "snap/2026/06/11/cam_01_20260611193004.jpg",
      "ts": 1749668404,
      "time": "19:30:04"
    }
  ]
}
```

- `now` = heure serveur (`time()`). Le front l'utilise pour estimer le
  décalage d'horloge client et calculer la fraîcheur.
- `last_ts` = epoch de la dernière image disponible (même si la liste est
  tronquée). Sert au calcul du warning « image périmée ».
- `images` ordonné ancien → récent.
- `ts` = epoch dérivé du **nom de fichier**, pas de `filemtime`.
- `time` = heure locale FR formatée pour affichage direct.
- Liste bornée (anti-abus, voir §6).
- `url` est **relative à la racine de l'app**, jamais absolue. Un
  consommateur d'une autre origine (cas CORS) connaît déjà cette racine —
  c'est là qu'il a appelé `images.php` — et préfixe lui-même. Le format ne
  contient donc aucune URL à réécrire lors d'un changement de domaine.
- La fenêtre couvre **tous** les dossiers jour qu'elle traverse, pas
  seulement le premier et le dernier : une fenêtre de plus de 24 h reste
  complète.

#### Warning « image périmée »

Le front affiche un bandeau d'alerte (dans les deux modes, live + timelapse)
si la dernière image date de plus de **5 min** (`now − last_ts > 300`).
Indique une caméra probablement hors service. Le calcul utilise l'heure
serveur (`now`) corrigée du décalage d'horloge client, pas l'horloge locale.

### `GET latest.php`

- Réponse : flux binaire JPEG.
- `Content-Type: image/jpeg`.
- En-têtes anti-cache (image toujours fraîche).
- Code 404 + message propre si aucune image disponible.

---

## 5. Format des fichiers source

- Chemin : `snap/AAAA/MM/JJ/<CAM_PREFIX>_<canal>_AAAAMMJJhhmmss.jpg`.
- Nom : `<CAM_PREFIX>_<canal>_<timestamp>.jpg`
  - `<CAM_PREFIX>` = préfixe device, configurable (`CAM_PREFIX`).
  - `<canal>` = canal caméra (un ou plusieurs chiffres).
  - `AAAAMMJJhhmmss` = horodatage (source de vérité pour l'heure affichée).
- Parsing du timestamp depuis le nom → indépendant de l'horloge serveur/FTP
  et de `filemtime` (qui peut être faussé par la copie/upload).

---

## 6. Sécurité

- Endpoints en lecture seule (sauf cleanup déclenché par `images.php`).
- Aucune entrée utilisateur passée à `unlink`/`copy`/chemin sans validation.
- Le scan reste confiné à `snap_root()` (pas de traversée de chemin).
- `images.php` borne le nombre d'images renvoyées (anti-abus) et **throttle
  le cleanup à 1 passage/min** (endpoint public → pas de scandir/unlink
  déclenchables à volonté).
- `latest.php` valide que le fichier servi est bien sous `snap_root()`.
- **CORS `images.php`** : liste blanche d'origines explicite
  (`CORS_ALLOWED_ORIGINS`), jamais `*`. Endpoint en lecture seule de données
  publiques non sensibles ; l'écho de l'origine n'autorise pas de credentials
  (pas de `Access-Control-Allow-Credentials`). Pour ajouter un consommateur,
  étendre la liste — ne pas passer en `*`.
- **`.htaccess` racine (unique)** : liste blanche PHP — tout `.php`
  refusé sauf `index|latest|images.php` ; `lib.php`, `config.php`, docs et
  listing de dossiers bloqués (`Options -Indexes`).
- **Sous `snap/` (dossier d'upload FTP caméra), liste blanche de chemins** :
  seul `AAAA/MM/JJ/<préfixe>_<canal>_<horodatage>.jpg` est servi ; tout le
  reste est refusé. Le filtre porte sur le nom complet, pas sur le seul
  suffixe `.jpg` — un `evil.php.jpg` passerait sinon, et un serveur en
  `AddHandler application/x-httpd-php .php` (courant en mutualisé) mappe sur
  PHP dès qu'une extension `.php` apparaît, pas seulement la dernière.
- Une règle rewrite `[F]` générique refuse en plus tout `.php` hors des trois
  points d'entrée, y compris un fichier nommé `index.php` que la liste
  blanche accepterait par nom (`FilesMatch` matche le nom, pas le chemin).
  Ordre des règles significatif — voir commentaires du fichier.
- En-têtes : CSP stricte + `X-Frame-Options` + `Referrer-Policy` sur la
  page ; `X-Content-Type-Options: nosniff` partout (un faux `.jpg` déposé
  par FTP ne peut pas être réinterprété en HTML par le navigateur).
- Reste côté infra (hors code) : compte FTP caméra dédié et chrooté sur
  `snap/`, FTPS si la caméra le supporte. Voir [DEPLOIEMENT.md](DEPLOIEMENT.md).

---

## 7. Performance

- Les listings sont bornés aux dossiers jour couverts par la fenêtre (un ou
  deux en rétention 1 h). `latest_image` et le cleanup parcourent l'arbre,
  mais celui-ci reste minuscule sous une rétention courte, et le cleanup est
  throttlé à un passage par minute.
- En-têtes de cache :
  - image courante (`latest.php`) : non cachée.
  - vieilles images (timelapse, noms horodatés) : `Cache-Control: public,
    max-age=86400, immutable` via `.htaccess` (noms horodatés → contenu
    immuable, le timelapse ne re-télécharge pas).

---

## 7b. Charte graphique

Deux couleurs configurables (`THEME_COLOR`, `THEME_ACCENT`), déclinées en
variables CSS.

| Token | Valeur | Usage |
|-------|--------|-------|
| `--bleu` | `#045BCB` | Primaire : titre, boutons actifs, accents, liens |
| `--bleu-fonce` | `#034399` | Hover / états pressés (bleu assombri) |
| `--vert` | `#AECD47` | Accent secondaire : bouton télécharger, lecture |
| `--blanc` | `#FFFFFF` | Fond logo, surfaces claires |

**Approche** : bleu dominant, vert en accent ponctuel, image webcam en
vedette. Thème auto via `prefers-color-scheme` :

- **Clair** : fond blanc/gris très clair, texte sombre, bleu primaire.
- **Sombre** : fond `#0d1117`-ish, texte clair, bleu éclairci si besoin
  pour le contraste (AA), vert inchangé.

Police : système (`system-ui`/sans-serif), pas de webfont (zéro dépendance).

---

## 8. Environnement & contraintes

- **PHP 8.x**, pas de Composer, pas de dépendance externe.
- **JS** vanilla ES6, pas de bundler, inline ou fichier(s) séparé(s).
- **CSS** fait main, thème auto via `prefers-color-scheme`.
- **Pas de cron** supposé disponible → tout traitement (image stable,
  cleanup) se fait à la volée dans les requêtes PHP.

---

## 9. Point de vigilance — test local

Le `.htaccess` est **ignoré par `php -S`** : les protections qu'il porte
(§6) ne se vérifient qu'en production, via la checklist de
[DEPLOIEMENT.md](DEPLOIEMENT.md).

Pour tester le timelapse en local, déposer quelques JPEG dans
`snap/AAAA/MM/JJ/` en respectant le format de nom (§5), avec des
horodatages compris dans la fenêtre courante — sinon le cleanup les
supprime au premier appel d'`images.php`.

Serveur de dev : `php -S 127.0.0.1:8000` à la racine du projet. En dev,
utiliser `latest.php` (la réécriture vers `latest.jpg` est faite par
Apache).
