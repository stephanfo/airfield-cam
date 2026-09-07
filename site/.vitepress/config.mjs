import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitepress'

// Le domaine vit dans site/CNAME — le fichier que lit GitHub Pages — et nulle part ailleurs ici.
// Le déclarer une seconde fois exposerait à ce qu'un sitemap continue d'annoncer un hôte mort
// après un changement de sous-domaine, sans que rien n'échoue.
const domaine = readFileSync(new URL('../CNAME', import.meta.url), 'utf8').trim()

export default defineConfig({
  title: 'Airfield Cam',
  description:
    'Page webcam pour terrain d’aviation : image en direct et timelapse rejouable, en PHP sans dépendance ni build.',
  lang: 'fr-FR',

  // Pas de `base` : le site est monté à la racine du sous-domaine. La vitrine `site/index.html`
  // occupe `/` (elle écrase au montage l'index généré par VitePress, cf. site/build-local.sh),
  // et l'arborescence du dépôt place naturellement la documentation sous /doc/.

  // La racine du dépôt : c'est ce qui permet d'agréger `doc/` ET les fichiers de la racine
  // (README, CONTRIBUTING, SECURITY) dans un seul site sans en déplacer aucun.
  //
  // Conséquence : Vite résout les imports des pages depuis LEUR emplacement, la racine du dépôt,
  // qui n'a pas de node_modules — le build échouerait sur « Rollup failed to resolve import
  // "vue/server-renderer" ». C'est le rôle du lien symbolique posé par site/lien-modules.mjs.
  srcDir: '..',

  // `srcDir` pointant hors du dossier du projet VitePress, ces deux chemins doivent être
  // explicites : sinon VitePress écrirait à la racine du dépôt. Ils sont relatifs à `site/`.
  outDir: './.vitepress/dist',
  cacheDir: './.vitepress/.cache',

  srcExclude: [
    'CLAUDE.md',      // instructions d'assistant, sans intérêt public
    'snap/**',        // dépôt FTP de la caméra
    'brand/**',       // assets de marque de l'instance, hors dépôt
    'tools/**',
    'site/**',        // dont site/node_modules
    '.github/**',
    '.claude/**',
  ],

  // Pas de `rewrites` : les pages conservent l'arborescence du dépôt, donc les liens relatifs
  // qui traversent le corpus (une trentaine) valent à la fois sur github.com et ici.
  //
  //   doc/PRD.md       → /doc/PRD
  //   README.md        → /README
  //   CONTRIBUTING.md  → /CONTRIBUTING
  //
  // Le README est INCLUS, contrairement à l'usage courant : dans ce projet, c'est LUI la
  // documentation d'installation et de référence, et CONTRIBUTING y renvoie. Il ne concurrence
  // pas la vitrine, qui présente le projet là où le README explique comment l'installer.

  // L'application suit le thème système (PRD §3, « Thème : auto ») : son site propose donc la
  // bascule clair/sombre. Un site clair-seulement illustré de captures sombres se contredirait.
  appearance: true,

  lastUpdated: true,

  // Volontairement laissé à `false` (le défaut) : un lien mort fait échouer le build. C'est le
  // seul test automatisé du site, et il remplace la relecture des liens croisés du corpus.
  // Corollaire : renommer ou supprimer un .md casse la publication tant que les liens qui le
  // visent n'ont pas été mis à jour.
  // ignoreDeadLinks: false,

  sitemap: { hostname: `https://${domaine}/` },

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/assets/favicon.svg' }],
    // THEME_COLOR de config.example.php : le site et l'application affichent la même couleur
    // dans la barre d'adresse mobile.
    ['meta', { name: 'theme-color', content: '#045BCB' }],
  ],

  vite: {
    // `srcDir` pointant sur la racine du dépôt, VitePress y chercherait son dossier `public` —
    // qui n'existe pas, et qui serait de toute façon celui de l'application. On le désigne donc
    // explicitement : ce qu'il contient est servi tel quel, sans hachage, ce qui donne des URL
    // stables (/assets/favicon.svg, /img/live.jpg) utilisables depuis la vitrine statique.
    publicDir: fileURLToPath(new URL('../public', import.meta.url)),
  },

  themeConfig: {
    logo: '/assets/favicon.svg',
    siteTitle: 'Airfield Cam',
    // Même raison que « Accueil » dans `nav` : le titre de la barre pointe vers la vitrine, qui
    // n'est pas une page VitePress. Sans `target: '_self'`, le routeur SPA l'intercepte et
    // affiche une 404 au lieu de la page d'accueil.
    logoLink: { link: '/', target: '_self' },

    search: {
      provider: 'local',
      options: {
        translations: {
          button: { buttonText: 'Rechercher', buttonAriaLabel: 'Rechercher' },
          modal: {
            displayDetails: 'Afficher les détails',
            resetButtonTitle: 'Réinitialiser',
            backButtonTitle: 'Fermer',
            noResultsText: 'Aucun résultat pour',
            footer: {
              selectText: 'pour sélectionner',
              navigateText: 'pour naviguer',
              closeText: 'pour fermer',
            },
          },
        },
      },
    },

    // Niveaux 2-3 seulement : TECHNIQUE.md et DEPLOIEMENT.md portent beaucoup de titres de
    // niveau 4, qui rendraient la colonne de droite illisible.
    outline: { level: [2, 3], label: 'Sur cette page' },

    nav: [
      // `target: '_self'` est INDISPENSABLE : la racine n'est pas une page VitePress mais la
      // vitrine statique (site/index.html, qui écrase l'index de VitePress au montage — cf.
      // build-local.sh). Sans lui, le routeur SPA intercepte le clic et cherche le payload JS
      // de la route « / », qui n'existe pas : l'utilisateur tombe sur une 404.
      { text: 'Accueil', link: '/', target: '_self' },
      { text: 'Documentation', link: '/doc/' },
      { text: 'Voir en direct', link: 'https://aeroclub-ancenis.fr/webcam/' },
      { text: 'GitHub', link: 'https://github.com/stephanfo/airfield-cam' },
    ],

    // Groupée par intention de lecture, pas par emplacement des fichiers : « j'installe »,
    // « je comprends », « je sécurise », « je contribue ».
    sidebar: [
      {
        text: 'Démarrer',
        items: [
          { text: 'Vue d’ensemble', link: '/doc/' },
          { text: 'Installation & prise en main', link: '/README' },
          { text: 'Mise en production', link: '/doc/DEPLOIEMENT' },
        ],
      },
      {
        text: 'Comprendre',
        items: [
          { text: 'Spécification produit (PRD)', link: '/doc/PRD' },
          { text: 'Documentation technique', link: '/doc/TECHNIQUE' },
        ],
      },
      {
        text: 'Sécuriser',
        items: [
          { text: 'Revue de sécurité', link: '/doc/SECURITE' },
          { text: 'Signaler une vulnérabilité', link: '/SECURITY' },
        ],
      },
      {
        text: 'Contribuer',
        items: [{ text: 'Guide de contribution', link: '/CONTRIBUTING' }],
      },
    ],

    socialLinks: [{ icon: 'github', link: 'https://github.com/stephanfo/airfield-cam' }],

    editLink: {
      pattern: 'https://github.com/stephanfo/airfield-cam/edit/main/:path',
      text: 'Proposer une correction sur GitHub',
    },

    lastUpdatedText: 'Dernière mise à jour',
    docFooter: { prev: 'Précédent', next: 'Suivant' },
    darkModeSwitchLabel: 'Apparence',
    returnToTopLabel: 'Haut de page',
    sidebarMenuLabel: 'Documentation',
    outlineTitle: 'Sur cette page',

    footer: {
      message: 'Publié sous licence AGPL-3.0.',
      copyright: 'Une caméra, un dossier FTP, quelques fichiers PHP. Rien d’autre.',
    },
  },
})
