# Déploiement

Installation et vérification en production. L'installation de base est dans
le [README](../README.md) ; ce document couvre ce qui ne se teste **qu'en
ligne**.

Dans ce qui suit, `<base>` désigne le chemin d'installation : `/webcam/`
pour un sous-dossier, `/` pour une installation à la racine du domaine.

## 1. Avant la mise en ligne

- [ ] `cp config.example.php config.php`, puis renseigner au minimum
      `CAM_PREFIX`, `SITE_TITLE`, `SITE_BASE_URL`, `SITE_TIMEZONE`.
- [ ] `.htaccess` : ajuster `RewriteBase` sur `<base>`, et les deux
      `ErrorDocument` sur la même valeur — les trois lignes sont marquées
      « ⚠️ À ADAPTER » dans le fichier.
- [ ] Dossier `snap/` créé et inscriptible par **deux** comptes : celui de
      la caméra (dépôt FTP) et celui du serveur web (ménage). Il n'y a pas
      de cron : c'est PHP qui supprime, donc il lui faut le droit d'écrire
      dans les dossiers `AAAA/MM/JJ`, pas seulement de les lire.
      Sur la plupart des mutualisés les deux comptes n'en font qu'un et
      `chmod 755` suffit. S'ils diffèrent, un groupe commun et `chmod 775`
      (plus `chmod g+s snap` pour que les sous-dossiers créés par FTP
      l'héritent) sont nécessaires — sinon `unlink` échoue en silence et
      `snap/` grossit jusqu'à saturer le quota.
- [ ] Assets remplacés si vous ne gardez pas les placeholders
      (`logo.png`, `favicon.png`, `apple-touch-icon.png`, `og-image.jpg`).
- [ ] Caméra configurée : envoi FTP vers `snap/`, création des
      sous-dossiers `AAAA/MM/JJ`, nom de fichier
      `<CAM_PREFIX>_<canal>_AAAAMMJJhhmmss.jpg`.

## 2. Checklist après mise en ligne

⚠️ **`php -S` ignore totalement le `.htaccess`** : rien de ce qui suit n'est
confirmé par le serveur de développement.

Il l'est en revanche par un vrai Apache, même local :

```bash
tools/test-htaccess.sh
```

Le script monte un docroot jetable, y copie votre `.htaccess` (donc il valide
aussi votre `RewriteBase`), dépose dans `snap/` les fichiers qu'un compte FTP
compromis y mettrait, et vérifie le code HTTP de chaque URL — en configuration
nominale, puis sans `mod_headers`, puis sans `mod_rewrite`. Sortie 0 si tout
passe. À lancer avant chaque mise en ligne touchant au `.htaccess`.

Les vérifications ci-dessous restent utiles : elles confirment que
l'hébergeur applique bien le fichier, ce qu'aucun test local ne peut dire.

### L'application répond

- [ ] `GET <base>` → la page s'affiche, le timelapse charge ses images.
- [ ] `GET <base>latest.jpg` → **200**, une image JPEG.
- [ ] `GET <base>images.php` → **200**, du JSON dont les `url` sont
      **relatives** (`snap/AAAA/MM/JJ/...`).

### Les fichiers internes sont inaccessibles

- [ ] `GET <base>lib.php` → **403**
- [ ] `GET <base>config.php` → **403** ← contient votre configuration
- [ ] `GET <base>config.example.php` → **403**
- [ ] `GET <base>doc/SECURITE.md` → **403**
- [ ] `GET <base>site/index.html` → **403** (si le dépôt est déployé tel quel :
      les sources du site de documentation n'ont rien à faire en ligne)
- [ ] `GET <base>.git/HEAD` → **403/404** (si le dépôt est déployé tel quel)
- [ ] `GET <base>snap/2026/` → **403**, pas de listing de dossier

### Le dossier d'upload n'exécute rien

Le test qui compte. Déposer **par FTP** dans un dossier jour de `snap/`, puis
demander chaque fichier par le navigateur :

- [ ] `test.php` → **403**
- [ ] `index.php` → **403** ← le cas que la liste blanche seule laisserait
      passer (elle filtre par nom de fichier, pas par chemin)
- [ ] `test.PhP` → **403** (le mapping handler Apache est insensible à la
      casse)
- [ ] `test.phtml` → **403**
- [ ] `test.html` → **403**
- [ ] `evil.php.jpg` → **403** ← double extension : le nom finit bien par
      `.jpg`, mais un serveur en `AddHandler ... .php` l'exécuterait. C'est
      la raison d'être de la liste blanche de chemins.
- [ ] `cam_01_20260611193004.jpg` déposé **à plat** dans `snap/` (hors
      `AAAA/MM/JJ/`) → **403** : seul le chemin complet du contrat est servi.

**Supprimer ces fichiers de test ensuite.**

### En-têtes

- [ ] `curl -I` sur un snapshot de `snap/` → `Cache-Control: public,
      max-age=86400, immutable`, **sans** `Pragma` ni `Expires`.
- [ ] `curl -I <base>logo.png` → `Cache-Control: public, max-age=604800`.
- [ ] `curl -I <base>` → `Content-Security-Policy`, `X-Frame-Options`,
      `X-Content-Type-Options` présents ; `X-Powered-By` absent.

## 3. Problèmes courants

**Erreur 500 au premier accès** — l'hébergeur refuse une directive du
`.htaccess`. Premier suspect : `Options -Indexes`, souvent interdit selon la
valeur d'`AllowOverride`. La retirer et retester ; les directives `Require`
passent sur tout Apache 2.4.

Second suspect : **`mod_rewrite` absent**. Le log Apache dit alors
`Invalid command 'RewriteEngine'`. Ce 500 est volontaire et ne doit pas être
contourné en enveloppant le bloc rewrite dans un `<IfModule>` : mesuré, cela
rend `snap/AAAA/MM/JJ/index.php`, `evil.php.jpg` et n'importe quel fichier
déposé par FTP servables (200 au lieu de 403), parce que la liste blanche
`FilesMatch` porte sur le nom de fichier et non sur le chemin. Il faut
activer le module, ou changer d'hébergeur.

`mod_headers`, lui, est déjà enveloppé dans un `<IfModule>` : son absence
fait perdre les en-têtes de cache, rien de plus.

**La page s'affiche mais reste vide** — presque toujours le format des noms
de fichiers. Vérifier qu'un fichier de `snap/` correspond exactement à
`<CAM_PREFIX>_<canal>_AAAAMMJJhhmmss.jpg`, et que `CAM_PREFIX` dans
`config.php` correspond à ce que la caméra envoie réellement. Tout fichier
non conforme est ignoré **en silence**.

Deuxième suspect, si les noms sont bons : le **fuseau**. L'horodatage du nom
est interprété dans `SITE_TIMEZONE`. Si la caméra écrit en UTC et que
`SITE_TIMEZONE` vaut `Europe/Paris`, chaque image paraît vieille de deux
heures et sort de la fenêtre. Régler la caméra sur le même fuseau que
`SITE_TIMEZONE`. Un décalage dans l'autre sens (images datées dans le futur)
est également rejeté au-delà de 24 h.

**Après un changement de `CAM_PREFIX`** — les images à l'ancien préfixe
deviennent invisibles *et* ne sont plus nettoyées : le cleanup ne supprime
que ce qu'il sait lire. Les retirer de `snap/` à la main.

**`snap/` grossit sans fin / les vieilles images ne disparaissent pas** —
le compte du serveur web n'a pas le droit de supprimer dans `snap/` (voir
§1). Les erreurs de `unlink` sont volontairement silencieuses : la seule
façon de le voir est de comparer le nombre de fichiers d'un jour ancien
avant et après un chargement de `images.php`. Second suspect : un
`CAM_PREFIX` changé depuis (ci-dessus).

**403 sur toute l'application** — `RewriteBase` ne correspond pas au chemin
réel, ou les règles du `.htaccess` ont été réordonnées : les passthrough
`[L]` doivent précéder la règle `[F]` générique.

**Les images ne se rafraîchissent pas** — vérifier que la caméra pousse bien
dans `snap/AAAA/MM/JJ/` et non à plat dans `snap/`.

**Le timelapse est plus court qu'attendu** — `RETENTION_SECONDS` est
probablement inférieur à `WINDOW_SECONDS` : l'app borne la fenêtre à ce qui
est réellement conservé.

## 4. À traiter côté infrastructure

Hors du périmètre du code, mais déterminant pour la sécurité de l'ensemble.

| Sujet | Recommandation |
|---|---|
| **Compte FTP caméra** | Compte **dédié**, jamais le compte principal de l'hébergement. Restreint (chrooté) au dossier `snap/`, mot de passe long généré aléatoirement. C'est la porte d'entrée du risque résiduel. |
| **FTPS** | Le FTP transmet le mot de passe en clair. Activer FTPS si la caméra le supporte. |
| **Caméra** | Firmware à jour, mot de passe admin fort, interface web **non exposée à Internet** (pas de redirection de port ni d'UPnP vers elle). |
| **HTTPS** | Vérifier que `http://` redirige vers `https://`. |
| **Compte d'hébergement** | Authentification à deux facteurs, mot de passe unique. |
| **Surveillance** | La page signale une caméra muette depuis plus de 5 minutes, mais seulement si quelqu'un regarde. Un service de ping externe sur `latest.jpg`, avec alerte sur 404, comble le manque. |
| **Sauvegardes** | Rien à sauvegarder côté images (rétention courte volontaire). Le code doit avoir une copie hors de la machine de développement. |

## 5. Mise à jour

`config.php` et le dossier `snap/` ne sont pas versionnés : une mise à jour
consiste à remplacer les fichiers de code sans y toucher.

Après mise à jour, comparer `config.example.php` avec votre `config.php` :
une constante ajoutée en amont doit être reportée chez vous. À défaut, l'app
refuse de démarrer avec un message qui **nomme les constantes fautives** — et
non par une page blanche :

```
Configuration invalide :
- MAX_IMAGES : constante absente
- SITE_TIMEZONE : identifiant de fuseau IANA attendu (ex. 'Europe/Paris')
Comparer config.php avec config.example.php (doc/DEPLOIEMENT.md §5).
```

Le contrôle porte sur la **présence et la forme** (voir `CONFIG_SPEC` dans
`lib.php`) : type attendu, fuseau existant dans la base IANA, couleurs en
hexadécimal, `CAM_PREFIX` limité à `[A-Za-z0-9_-]`. C'est délibérément plus
strict qu'un simple contrôle de présence, parce que les erreurs de forme sont
celles qui ne se voient pas — un fuseau mal orthographié faisait auparavant
retomber PHP sur UTC et décalait toutes les heures affichées sans un mot, et
un `CAM_PREFIX` hors charset produit une page dont Apache refuse chaque image
alors que le serveur de dev l'affiche parfaitement.

Le message ne reprend jamais la valeur reçue : il s'affiche sur des endpoints
publics.

Le premier chargement de la page après mise à jour suffit donc à valider la
configuration.
