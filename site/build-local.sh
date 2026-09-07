#!/usr/bin/env bash
#
# Assemble le site complet dans _site/ : la vitrine à la racine, la documentation sous /doc/.
#
#   ./site/build-local.sh            construit
#   ./site/build-local.sh --serve    construit puis sert sur http://localhost:8080
#
# Ce script est la RÉFÉRENCE du workflow GitHub Actions : ce qui marche ici doit marcher en CI,
# parce que ce sont les mêmes étapes dans le même ordre. Toute divergence entre les deux est un
# bug en attente.
#
# Il ne touche à RIEN de l'application : npm et le build n'existent que sous site/.

set -euo pipefail

cd "$(dirname "$0")/.."          # racine du dépôt
OUT="_site"

if [ ! -d site/node_modules ]; then
  echo "Dépendances du site absentes. Lancer d'abord :  npm --prefix site install" >&2
  exit 1
fi

# Vite résout les imports des pages depuis la racine du dépôt, qui n'a pas de node_modules :
# ce lien symbolique (gitignoré, artefact de build) le lui fournit. Détail dans le fichier.
echo "→ Lien node_modules"
node site/lien-modules.mjs

echo "→ Nettoyage"
rm -rf "$OUT" site/.vitepress/dist

# --- Les captures de la vitrine ----------------------------------------------------------------
# doc/screenshot/ reste la source unique : GitHub y lit les mêmes fichiers, et le README les
# affiche déjà. On les expose sous /img/ pour la page d'accueil, sans les dupliquer dans le dépôt.
echo "→ Copie des captures"
mkdir -p site/public/img
for f in live timelapse; do
  cp "doc/screenshot/$f.jpg" site/public/img/
done

# --- L'image de partage ------------------------------------------------------------------------
# Celle de l'application (placeholder générique régénérable par tools/generate-assets.php).
# Copiée plutôt que versionnée une seconde fois : une seule image à remplacer le jour venu.
echo "→ Copie de l'image de partage"
cp og-image.jpg site/public/assets/og.jpg

# --- La documentation --------------------------------------------------------------------------
# Échoue sur lien mort : c'est voulu, cf. `ignoreDeadLinks` dans la configuration.
echo "→ Construction de la documentation"
npm --prefix site run doc:build

# --- Montage -----------------------------------------------------------------------------------
echo "→ Montage de $OUT"
mkdir -p "$OUT"
cp -r site/.vitepress/dist/. "$OUT"/          # doc + assets publics (logos, captures, og)
cp site/index.html site/style.css "$OUT"/     # la vitrine écrase l'index de VitePress

# Sans ce fichier, GitHub Pages passe la sortie dans Jekyll, qui ignore tout chemin commençant
# par un souligné.
touch "$OUT/.nojekyll"

cp site/CNAME "$OUT/CNAME"
DOMAINE=$(tr -d '[:space:]' < site/CNAME)

# La vitrine n'est pas une page VitePress : elle est absente du sitemap généré. Or c'est la
# racine du domaine, donc la seule URL dont l'indexation ne se discute pas. On l'ajoute en tête.
python3 - "$OUT/sitemap.xml" "$DOMAINE" <<'SITEMAP'
import pathlib, sys

fichier, domaine = pathlib.Path(sys.argv[1]), sys.argv[2]
xml = fichier.read_text(encoding='utf-8')
accueil = f'<url><loc>https://{domaine}/</loc></url>'
marque = '>'
debut = xml.index('<urlset')
insertion = xml.index(marque, debut) + 1
fichier.write_text(xml[:insertion] + accueil + xml[insertion:], encoding='utf-8')
SITEMAP

cat > "$OUT/robots.txt" <<ROBOTS
User-agent: *
Allow: /

Sitemap: https://$DOMAINE/sitemap.xml
ROBOTS

# --- Vérifications -----------------------------------------------------------------------------
# Ces contrôles couvrent des pannes silencieuses : un site qui se construit sans erreur mais dont
# les captures manquent, ou dont la vitrine annonce un domaine qui n'est plus le bon, n'a l'air
# cassé pour personne.
echo "→ Vérifications"
fail=0
for f in index.html style.css CNAME .nojekyll robots.txt sitemap.xml \
         assets/favicon.svg assets/logo-blanc.svg assets/og.jpg \
         img/live.jpg img/timelapse.jpg \
         doc/index.html doc/PRD.html doc/TECHNIQUE.html doc/SECURITE.html doc/DEPLOIEMENT.html \
         README.html CONTRIBUTING.html SECURITY.html; do
  if [ ! -e "$OUT/$f" ]; then echo "   ✗ manquant : $f"; fail=1; fi
done

# Le domaine vit à deux endroits inévitables : site/CNAME (que lit GitHub Pages) et les balises
# Open Graph de la vitrine, qui exigent des URL absolues. Ils doivent concorder, sinon l'aperçu
# de lien pointe un domaine mort sans que rien n'échoue.
if ! grep -q "https://$DOMAINE/" "$OUT/index.html"; then
  echo "   ✗ la vitrine n'annonce pas $DOMAINE dans ses métadonnées Open Graph"; fail=1
fi

# La vitrine doit avoir écrasé l'index de VitePress : sans cela, la racine du domaine afficherait
# la page d'accueil du thème, et les deux CTA seraient perdus.
if ! grep -q 'Voir une instance en direct' "$OUT/index.html"; then
  echo "   ✗ index.html n'est pas la vitrine (index de VitePress non écrasé ?)"; fail=1
fi

# La racine doit figurer au sitemap : VitePress ne l'y met pas, c'est l'étape d'insertion
# ci-dessus qui s'en charge — et elle dépend du format de sortie de VitePress.
if ! grep -q "<loc>https://$DOMAINE/</loc>" "$OUT/sitemap.xml"; then
  echo "   ✗ la vitrine n'est pas dans sitemap.xml"; fail=1
fi

[ "$fail" -eq 0 ] && echo "   ✓ tout est en place"
[ "$fail" -eq 0 ] || { echo "Assemblage incomplet."; exit 1; }

echo
echo "Site assemblé dans $OUT/ ($(du -sh "$OUT" | cut -f1))"

if [ "${1:-}" = "--serve" ]; then
  PORT="${PORT:-8080}"
  echo "→ http://localhost:$PORT  (Ctrl+C pour arrêter ; PORT=8099 pour en changer)"
  # Pour vérifier le rendu mobile, utiliser la bascule d'appareil des outils de développement
  # du navigateur. À NE PAS faire : une capture Chrome `--headless --window-size=390,…` —
  # le mode headless impose un viewport d'au moins 500 px de large, rend la page pour 500 px
  # puis la capture à 390, ce qui simule un débordement qui n'existe pas.
  #
  # Serveur minimal qui résout /doc/PRD vers doc/PRD.html, comme le fait GitHub Pages.
  # `python3 -m http.server` ne le fait PAS : il renverrait 404 sur toutes les pages et
  # donnerait l'illusion d'un site cassé.
  cd "$OUT" && PORT="$PORT" python3 - <<'SERVE'
import http.server, os, socketserver

class H(http.server.SimpleHTTPRequestHandler):
    def translate_path(self, path):
        p = super().translate_path(path)
        if not os.path.exists(p) and not path.endswith('/') and os.path.exists(p + '.html'):
            return p + '.html'
        return p

    def log_message(self, *a):
        pass

with socketserver.TCPServer(('', int(os.environ.get('PORT', '8080'))), H) as s:
    s.serve_forever()
SERVE
fi
