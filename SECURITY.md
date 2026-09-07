# Politique de sécurité

## Signaler une vulnérabilité

**Merci de ne pas ouvrir d'issue publique** pour une faille de sécurité.

Utilisez la fonction *Report a vulnerability* de l'onglet **Security** du
dépôt GitHub, qui ouvre un signalement privé.

Merci d'inclure : une description du problème, les étapes pour le
reproduire, et l'impact que vous estimez.

Projet maintenu bénévolement : comptez quelques jours pour une première
réponse. Vous serez tenu au courant du traitement, et crédité dans le
correctif si vous le souhaitez.

## Périmètre

Ce qui relève de ce dépôt :

- Le code PHP (`index.php`, `lib.php`, `images.php`, `latest.php`).
- Les règles du `.htaccess` fourni.
- Une configuration par défaut qui exposerait une instance.

Ce qui n'en relève pas — ce sont des questions de déploiement, traitées
dans [doc/SECURITE.md](doc/SECURITE.md) :

- La configuration du compte FTP de la caméra (le vecteur d'entrée
  principal), du serveur, ou du pare-feu.
- Le firmware de la caméra.
- Une instance déployée dont le `.htaccess` a été modifié ou dont
  l'hébergeur ignore les directives `AllowOverride`.

## Modèle de menace

L'application sert des pages publiques sans authentification : le contenu
n'est pas un secret. Le risque principal n'est pas le code PHP mais le
**dossier `snap/`, alimenté par FTP** — une zone d'écriture partiellement
hors du contrôle de l'application.

Un attaquant qui obtient les identifiants FTP de la caméra peut y déposer
des fichiers arbitraires. Le `.htaccess` empêche que ces fichiers soient
exécutés ou servis autrement que comme des `.jpg`. Cette protection dépend
de l'ordre des règles et de la prise en compte du `.htaccess` par le
serveur — d'où la checklist de [doc/DEPLOIEMENT.md](doc/DEPLOIEMENT.md).

## Versions supportées

Le projet n'a pas de versions publiées : seul l'état courant de la branche
`main` est maintenu. Les correctifs de sécurité y sont appliqués
directement.
