# Contribuer

Merci de l'intérêt. Le projet est petit et volontairement contraint : ces
quelques règles évitent le travail perdu de part et d'autre.

## Une faille de sécurité n'est pas une issue

N'ouvrez pas d'issue publique. La procédure de signalement privé est dans
[SECURITY.md](SECURITY.md).

## Les contraintes ne se négocient pas

Le public visé, c'est un club qui dépose des fichiers en FTP sur un
hébergement mutualisé, et un bénévole qui reprendra le code dans trois ans.
D'où :

- **aucune dépendance, aucun build** — ni Composer, ni npm, ni base de
  données ; le JS et le CSS restent vanilla et en ligne dans `index.php` ;
- **pas de cron** — tout traitement périodique se fait à la volée dans les
  requêtes ;
- **PHP 8.0** — pas de syntaxe 8.1+ dans le code de l'application (l'outil
  facultatif `tools/generate-assets.php` fait exception, et le vérifie).

Une contribution qui franchit une de ces limites sera refusée même si elle
est bonne par ailleurs. Ce n'est pas un principe esthétique : c'est ce qui
permet de déployer par FTP et de ne rien avoir à maintenir.

## Ce qui est particulièrement bienvenu

- Le support d'autres caméras — surtout si le format de nom de fichier
  diffère. C'est la première cause d'échec d'installation.
- Les pièges de déploiement Apache rencontrés chez un hébergeur donné.
- Les corrections de documentation : si vous avez buté sur une étape,
  c'est un bug de la doc.

L'interface est en français et n'est pas internationalisée. Une vraie i18n
est recevable, mais discutez-en dans une issue avant : elle touche à
beaucoup de choses pour un projet de cette taille.

## Avant d'ouvrir une pull request

```bash
php -l lib.php && php -l images.php && php -l latest.php \
  && php -l index.php && php -l config.example.php

# Obligatoire dès que vous touchez au .htaccess. Nécessite un Apache local
# (présent sur macOS, paquet apache2 sur Debian). Sortie 0 si tout passe.
tools/test-htaccess.sh
```

Il n'y a pas d'autres tests automatisés : la vérification passe par le
serveur de dev et `curl` (voir *Développement* dans le
[README](README.md)). Décrivez donc dans la PR ce que vous avez vérifié à
la main — c'est ce qui remplace la suite de tests.

## Les invariants à ne pas casser

Ils sont expliqués dans [CLAUDE.md](CLAUDE.md) et
[doc/TECHNIQUE.md](doc/TECHNIQUE.md). Les trois qui se cassent le plus
facilement sans que rien n'échoue visiblement :

- **L'heure d'une image vient de son nom de fichier**, jamais de
  `filemtime`, faussé par l'upload FTP.
- **L'ordre des règles du `.htaccess`** : le passthrough `[L]` des entrées
  légitimes doit rester avant les règles `[F]`. Les réordonner ouvre
  l'exécution de PHP déposé dans `snap/`.
- **L'ancre du ménage est bornée à l'heure courante.** Sans ce `min`, une
  horloge caméra en avance efface tout l'historique.

## Commits et pull requests

Messages en français, à l'impératif, qui disent **pourquoi** plutôt que
quoi — le diff dit déjà quoi. Une pull request traite un sujet.

En contribuant, vous acceptez que votre travail soit publié sous
[AGPL-3.0](LICENSE).
