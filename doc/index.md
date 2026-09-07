# Documentation

**Airfield Cam** publie la webcam d'un terrain d'aviation : la dernière image en
direct, et un timelapse rejouable de la dernière heure. Une caméra pousse ses
snapshots par FTP, quelques fichiers PHP les servent.

Le projet tient en une promesse : **PHP 8, zéro dépendance, zéro build, pas de
cron**. Ni Composer, ni npm, ni base de données, ni tâche planifiée — on dépose
les fichiers sur un hébergement mutualisé et ça tourne. Cette contrainte
explique la plupart des choix décrits ici, à commencer par le ménage des vieilles
images, fait à la volée dans les requêtes plutôt que par un cron.

## Par où commencer

| Vous voulez… | Lire |
|---|---|
| **Voir à quoi ça ressemble** | L'[instance de l'Aéroclub du Pays d'Ancenis](https://aeroclub-ancenis.fr/webcam/) |
| **Installer une instance** | [Installation & prise en main](../README.md) |
| **Mettre en ligne et vérifier** que c'est sûr | [Mise en production](DEPLOIEMENT.md) |
| **Comprendre le produit**, et ce qu'il ne fait pas | [Spécification produit](PRD.md) |
| **Comprendre l'architecture** et les contrats d'API | [Documentation technique](TECHNIQUE.md) |
| **Auditer la sécurité** du dépôt FTP | [Revue de sécurité](SECURITE.md) |
| **Contribuer** | [Guide de contribution](../CONTRIBUTING.md) |
| **Signaler une vulnérabilité** | [Politique de sécurité](../SECURITY.md) |

## Les documents

### Démarrer

- **[Installation & prise en main](../README.md)** — prérequis, dépôt des
  fichiers, configuration, réglage de la caméra, et le **format des noms de
  fichiers** : c'est le contrat entre la caméra et l'application, et la première
  cause d'échec au déploiement.
- **[Mise en production](DEPLOIEMENT.md)** — ce qui ne se teste **qu'en ligne** :
  droits du dossier `snap/`, checklist des règles du `.htaccess` (que le serveur
  de développement ignore), compte FTP dédié, dépannage.

### Comprendre

- **[Spécification produit](PRD.md)** — le « quoi » et le « pourquoi » : modes
  Live et Timelapse, décisions produit actées, et ce qui est **hors périmètre**
  (pas de flux vidéo temps réel, pas de comptes, pas de multi-caméras).
- **[Documentation technique](TECHNIQUE.md)** — architecture, contrats des
  endpoints (`latest.jpg`, `images.php`), rétention et ménage paresseux, et les
  invariants qui se cassent en silence — au premier rang desquels : **l'heure
  d'une image vient de son nom de fichier**, jamais de sa date système, faussée
  par l'upload FTP.

### Sécuriser

- **[Revue de sécurité](SECURITE.md)** — le dossier `snap/` est alimenté par
  FTP : c'est une zone d'écriture partiellement hors de votre contrôle. Ce
  document explique comment le `.htaccess` empêche l'exécution de tout code qui
  y serait déposé, et pourquoi l'ordre de ses règles ne se réarrange pas.
- **[Politique de sécurité](../SECURITY.md)** — comment signaler une
  vulnérabilité. **Pas d'issue publique.**

### Contribuer

- **[Guide de contribution](../CONTRIBUTING.md)** — les contraintes qui ne se
  négocient pas, ce qui est particulièrement bienvenu (le support d'autres
  caméras, surtout si le format de nom diffère), et les vérifications à passer
  avant d'ouvrir une pull request.

## Bon à savoir

**Le projet est en français** — code, commentaires, interface et documentation.
C'est un choix assumé, pas un oubli d'internationalisation.

**Il n'y a pas de suite de tests.** La vérification passe par le serveur de
développement, `curl`, et `tools/test-htaccess.sh` — le seul test automatisé,
qui rejoue les règles du `.htaccess` sur un vrai Apache.

**Rien n'est spécifique à l'aéronautique.** Le projet est né pour la page
publique d'un aéroclub, mais toute caméra poussant des JPEG horodatés par FTP
fait l'affaire.

---

> Une question sans réponse dans ces pages ? Les
> [issues GitHub](https://github.com/stephanfo/airfield-cam/issues) sont
> ouvertes — sauf pour les failles de sécurité, qui suivent la
> [procédure de signalement privé](../SECURITY.md).
