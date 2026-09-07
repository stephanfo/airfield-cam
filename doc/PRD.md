# PRD — Page webcam

**Statut :** ✅ implémenté
**Date de rédaction :** 2026-06-11
**Doc technique :** voir [TECHNIQUE.md](TECHNIQUE.md)

> Document d'intention, conservé comme trace des décisions produit et de
> leur justification. Il décrit le « quoi » et le « pourquoi » ; l'état
> réel de l'implémentation fait foi.

---

## 1. Contexte

Une caméra pousse des snapshots par FTP sur le serveur, dans une
arborescence `snap/AAAA/MM/JJ/<CAM_PREFIX>_<canal>_AAAAMMJJhhmmss.jpg`.

Le besoin initial dépassait le simple affichage de la dernière image :
offrir une vraie page de consultation : image courante, téléchargement pour applis
tierces, et un mode "timelapse" rejouant la dernière heure en accéléré.

**Cible utilisateur :** membres de l'aéroclub consultant la météo / l'état
de la piste avant un vol, depuis ordinateur ou mobile.

---

## 2. Objectifs

1. Afficher immédiatement la dernière image reçue à l'ouverture de la page.
2. Fournir une URL stable de l'image pour consommation par des applis tierces.
3. Permettre le téléchargement de l'image (humain) avec nom horodaté.
4. Offrir un mode "live accéléré" rejouant les images de la dernière heure.
5. Dans ce mode : lecture/pause, réglage de vitesse, navigation manuelle
   image par image, retour à l'image courante, horodatage affiché.
6. Interface propre, sobre, responsive, sans dépendance externe.

### Non-objectifs (hors périmètre v1)

- Authentification / comptes utilisateurs.
- Flux vidéo temps réel (RTSP/HLS) — on reste sur des snapshots JPEG.
- Multi-caméras (une seule caméra pour l'instant).
- Stockage long terme / archivage au-delà de la fenêtre de rétention.
- Détection de mouvement, alertes, IA.

---

## 3. Décisions produit actées (revue du 2026-06-11)

| Sujet | Décision |
|-------|----------|
| Fenêtre timelapse | Dernière heure glissante, fixe |
| URL appli | URL stable qui renvoie toujours la dernière image + bouton download nom horodaté |
| Refresh image | Auto (~20-30s) **+** bouton manuel |
| Design | Sobre, fait main, responsive, sans framework |
| Migration | Nouvelle page substituée à l'ancienne une fois validée |
| Fin timelapse | Au choix (toggle **Boucle**) : rejoue en boucle, ou **bascule auto en mode Live** (défaut) |
| MAJ timelapse | **Oui** : recharge périodique de la liste, rattrape le présent |
| Vitesse | **Presets ×1 / ×2 / ×4 / ×8**, défaut **×2** |
| Préférences | Vitesse + boucle **persistées** (`localStorage`), restaurées à chaque visite |
| Thème | **Auto** (clair/sombre selon le système) |
| Identité | Titre et logo configurables (`SITE_TITLE`, `logo.png`) |
| Horodatage affiché | Heure locale FR, source = nom de fichier |
| Navigation | Live ↔ Timelapse **bascule, même page**, une seule URL |

> Décisions d'implémentation (archi, endpoints, rétention, versions) :
> voir [TECHNIQUE.md](TECHNIQUE.md).

---

## 4. Spécifications fonctionnelles

### 4.1 Vue par défaut — Image courante

- À l'ouverture, l'image la plus récente s'affiche en grand, centrée.
- Horodatage de l'image affiché (ex: « 11/06/2026 20:24:04 »).
- Rafraîchissement automatique toutes les ~20-30 s (sans recharger la page).
- Bouton **« Rafraîchir »** pour forcer la mise à jour immédiate.
- Indicateur discret « dernière mise à jour il y a Xs ».
- Gestion d'erreur : si aucune image / image cassée → message propre,
  pas d'icône "image brisée".

### 4.2 Téléchargement

- Bouton **« Télécharger l'image »** :
  - force le téléchargement du fichier JPEG courant ;
  - nom de fichier horodaté, ex. `cam_20260611_202404.jpg`.
- URL stable de l'image (toujours la dernière) documentée sur la page
  (lien copiable) pour les applis tierces qui veulent l'image brute.

### 4.3 Mode Timelapse (« live accéléré »)

- Bascule **« Timelapse »** depuis la vue courante.
- Charge la liste de la dernière heure.
- Lecture automatique en accéléré (images jouées en séquence).
- **Contrôles :**
  - **Lecture / Pause**.
  - **Vitesse** : presets ×1 / ×2 / ×4 / ×8 (défaut ×2, choix mémorisé
    dans le navigateur via `localStorage`).
  - **Boucle** : toggle ; à la fin, rejoue depuis le début au lieu de
    revenir au Live (choix mémorisé également).
  - **Image suivante / précédente** (navigation manuelle pas-à-pas).
  - **Slider / timeline** : position dans l'heure, déplaçable.
  - **« Revenir à l'image courante »** : saute à la plus récente et
    repasse en vue courante.
- **Horodatage** de l'image affichée, mis à jour à chaque frame.
- À la fin de la séquence : **bascule automatiquement en mode Live**,
  sauf si la **boucle** est activée (rejeu depuis le début).
- Pendant la lecture, la liste se rafraîchit pour intégrer les images
  fraîches (le timelapse rattrape le présent).

### 4.4 Responsive / mobile

- Image s'adapte à la largeur de l'écran.
- Contrôles utilisables au doigt (boutons assez grands).
- Pas de scroll horizontal.

---

## 5. Découpage de livraison

- **Lot 1 — Socle**
  - Endpoint liste JSON (dernière heure).
  - Endpoint image stable (dernière image).
- **Lot 2 — Vue courante**
  - `index.php` : affichage image + horodatage + auto-refresh + bouton.
  - Bouton download + URL appli affichée.
- **Lot 3 — Timelapse**
  - Mode timelapse : lecture/pause, vitesse, pas-à-pas, slider,
    retour image courante, horodatage.
- **Lot 4 — Finitions**
  - CSS responsive, gestion d'erreurs, rétention/cleanup.
  - Bascule vers la nouvelle page.

Détail des composants et contrats : voir [TECHNIQUE.md](TECHNIQUE.md).

**État de livraison :** Lots 1-4 implémentés — `index.php`, `lib.php`,
`latest.php`, `images.php`.

---

## 6. Critères d'acceptation

- [ ] Ouvrir la page affiche la dernière image en < 2 s.
- [ ] L'image se rafraîchit seule et via le bouton.
- [ ] L'URL stable renvoie toujours l'image la plus récente.
- [ ] Le bouton télécharge un JPEG au nom horodaté.
- [ ] Le timelapse rejoue la dernière heure, vitesse réglable, pause OK.
- [ ] Navigation pas-à-pas et retour image courante fonctionnent.
- [ ] L'horodatage affiché correspond à l'image montrée.
- [ ] Page utilisable et lisible sur mobile.
- [ ] Aucune image conservée au-delà de la fenêtre de rétention.
