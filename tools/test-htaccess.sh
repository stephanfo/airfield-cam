#!/usr/bin/env bash
#
# Vérifie le .htaccess du projet sur un vrai Apache, hors production.
#
# La doc a longtemps dit que ce fichier n'était testable qu'en ligne : `php -S`
# l'ignore totalement. C'est faux dès qu'un Apache est installé localement —
# et ce n'est pas un luxe, ces règles sont l'essentiel de la sécurité du
# projet. Ce script monte un docroot jetable, y copie le .htaccess RÉEL (avec
# votre RewriteBase, donc il valide aussi cette ligne), dépose des fichiers
# hostiles dans snap/ comme le ferait un compte FTP compromis, et vérifie le
# code HTTP de chaque URL.
#
# Il rejoue aussi les deux configurations dégradées qui comptent : sans
# mod_headers (doit rester identique) et sans mod_rewrite (doit tout fermer,
# jamais s'ouvrir). Voir doc/SECURITE.md.
#
# Usage :  tools/test-htaccess.sh          depuis la racine du projet
#          PORT=9999 tools/test-htaccess.sh
#
# Sortie 0 si tout passe, 1 si une attente est violée, 2 si Apache est absent.
#
# Copyright (C) 2026 Stephanfo
#
# Ce programme est un logiciel libre : vous pouvez le redistribuer et/ou le
# modifier selon les termes de la GNU Affero General Public License version 3,
# telle que publiée par la Free Software Foundation. Distribué sans aucune
# garantie — voir le fichier LICENSE.

set -u

PORT="${PORT:-8787}"
PROJET="$(cd "$(dirname "$0")/.." && pwd)"
HTACCESS="$PROJET/.htaccess"

[ -f "$HTACCESS" ] || { echo "Pas de .htaccess dans $PROJET"; exit 2; }

# --- Localiser Apache et ses modules (macOS et Debian/RHEL) ----------------
HTTPD=""
for c in /usr/sbin/httpd /usr/sbin/apache2 "$(command -v httpd 2>/dev/null)" "$(command -v apache2 2>/dev/null)"; do
    [ -n "$c" ] && [ -x "$c" ] && { HTTPD="$c"; break; }
done
MODS=""
for d in /usr/libexec/apache2 /usr/lib/apache2/modules /usr/lib64/httpd/modules /usr/lib/httpd/modules; do
    [ -f "$d/mod_rewrite.so" ] && { MODS="$d"; break; }
done
if [ -z "$HTTPD" ] || [ -z "$MODS" ]; then
    echo "Apache introuvable (binaire httpd/apache2 ou dossier de modules)."
    echo "Ce test demande un Apache installé localement ; il n'a pas besoin de tourner."
    exit 2
fi

# --- Chemin d'installation : lu dans le .htaccess, donc on teste VOTRE ligne
BASE="$(awk '/^[[:space:]]*RewriteBase[[:space:]]/ {print $2; exit}' "$HTACCESS")"
BASE="${BASE:-/}"
case "$BASE" in */) ;; *) BASE="$BASE/";; esac

TMP="$(mktemp -d "${TMPDIR:-/tmp}/htaccess-test.XXXXXX")"
trap '"$HTTPD" -f "$TMP/httpd.conf" -k stop >/dev/null 2>&1; rm -rf "$TMP"' EXIT

APP="$TMP/htdocs${BASE%/}"
mkdir -p "$APP" "$TMP/logs"
cp "$HTACCESS" "$APP/.htaccess"
for f in index.php latest.php images.php lib.php config.example.php; do
    [ -f "$PROJET/$f" ] && cp "$PROJET/$f" "$APP/"
done
echo "config d'instance" > "$APP/config.php"

# Sources et sortie du site de documentation : elles se retrouvent en ligne dès
# que le dépôt est déployé tel quel. Rien de sensible, mais rien à y faire non
# plus — et _site/ servirait une seconde copie de la doc à une URL parallèle.
mkdir -p "$APP/site" "$APP/_site"
echo 'vitrine (sources)' > "$APP/site/index.html"
echo 'vitrine (assemblée)' > "$APP/_site/index.html"

# Zone d'écriture de la caméra : une image légitime, et ce qu'un compte FTP
# compromis y déposerait. Aucun PHP n'est exécuté ici (pas de handler chargé) :
# ce qu'on mesure est la décision d'autorisation, 200 contre 403.
SNAP="$APP/snap/2026/06/11"
mkdir -p "$SNAP"
echo jpeg > "$SNAP/cam_01_20260611193004.jpg"
for hostile in index.php shell.php evil.php.jpg x.pHtMl notes.txt; do
    echo hostile > "$SNAP/$hostile"
done
echo hostile > "$APP/snap/shell.php"

cat > "$TMP/httpd.conf.in" <<CONF
ServerName 127.0.0.1
Listen $PORT
PidFile "$TMP/httpd.pid"
ErrorLog "$TMP/logs/error.log"
DocumentRoot "$TMP/htdocs"
LoadModule mpm_prefork_module $MODS/mod_mpm_prefork.so
LoadModule authz_core_module  $MODS/mod_authz_core.so
LoadModule mime_module        $MODS/mod_mime.so
LoadModule dir_module         $MODS/mod_dir.so
LoadModule autoindex_module   $MODS/mod_autoindex.so
LoadModule unixd_module       $MODS/mod_unixd.so
@REWRITE@
@HEADERS@
DirectoryIndex index.php index.html
<Directory "$TMP/htdocs">
    AllowOverride All
    Require all granted
</Directory>
CONF

echec=0

http() {  # http <chemin-relatif-a-BASE>
    curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT$BASE$1"
}

demarrer() {  # demarrer <rewrite on|off> <headers on|off>
    sed -e "s|@REWRITE@|$([ "$1" = on ] && echo "LoadModule rewrite_module $MODS/mod_rewrite.so" || echo '# mod_rewrite absent')|" \
        -e "s|@HEADERS@|$([ "$2" = on ] && echo "LoadModule headers_module $MODS/mod_headers.so" || echo '# mod_headers absent')|" \
        "$TMP/httpd.conf.in" > "$TMP/httpd.conf"
    "$HTTPD" -f "$TMP/httpd.conf" -k start >/dev/null 2>&1
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        [ "$(http '')" != "000" ] && return 0
        sleep 0.3
    done
    return 0
}

arreter() { "$HTTPD" -f "$TMP/httpd.conf" -k stop >/dev/null 2>&1; sleep 0.5; }

verifier() {  # verifier <chemin> <code attendu> <intitulé>
    code="$(http "$1")"
    if [ "$code" = "$2" ]; then
        printf '  ok    %-3s  %s\n' "$code" "$3"
    else
        printf '  ECHEC %-3s  %s  (attendu %s)\n' "$code" "$3" "$2"
        echec=1
    fi
}

# Les URL de snap/ sont les seules qui comptent vraiment : c'est la zone où
# la caméra écrit, donc la seule qu'un tiers peut alimenter.
attentes_nominales() {
    verifier ''                                       200 'la page'
    verifier 'index.php'                              200 "point d'entrée index.php"
    verifier 'images.php'                             200 "point d'entrée images.php"
    verifier 'latest.jpg'                             200 'latest.jpg (réécriture)'
    verifier 'lib.php'                                403 'lib.php non servi'
    verifier 'config.php'                             403 'config.php non servi'
    verifier '.htaccess'                              403 '.htaccess non servi'
    verifier 'site/index.html'                        403 'site/ : sources du site de doc'
    verifier '_site/index.html'                       403 '_site/ : site assemblé'
    verifier 'snap/2026/06/11/cam_01_20260611193004.jpg' 200 'image conforme au contrat'
    verifier 'snap/2026/06/11/index.php'              403 'snap/ : index.php déposé par FTP'
    verifier 'snap/2026/06/11/shell.php'              403 'snap/ : shell.php'
    verifier 'snap/2026/06/11/evil.php.jpg'           403 'snap/ : double extension'
    verifier 'snap/2026/06/11/x.pHtMl'                403 'snap/ : .phtml, casse mélangée'
    verifier 'snap/2026/06/11/notes.txt'              403 'snap/ : fichier hors contrat'
    verifier 'snap/shell.php'                         403 'snap/ : PHP à la racine'
    verifier 'snap/'                                  403 'snap/ : listing'
}

echo "Apache   : $HTTPD"
echo "Modules  : $MODS"
echo "Chemin   : $BASE   (lu dans RewriteBase)"
echo

echo "[1/3] mod_rewrite + mod_headers — configuration nominale"
demarrer on on
attentes_nominales
cache="$(curl -sI "http://127.0.0.1:$PORT${BASE}snap/2026/06/11/cam_01_20260611193004.jpg" | tr -d '\r' | awk -F': ' 'tolower($1)=="cache-control"{print $2}')"
case "$cache" in
    *immutable*) printf '  ok         cache long sur les snapshots (%s)\n' "$cache";;
    *)           printf '  ECHEC      cache long attendu sur les snapshots, reçu : %s\n' "${cache:-aucun}"; echec=1;;
esac
arreter

echo
echo "[2/3] mod_headers absent — les autorisations doivent être identiques"
demarrer on off
attentes_nominales
arreter

echo
echo "[3/3] mod_rewrite absent — tout doit se fermer, rien ne doit s'ouvrir"
demarrer off on
for u in '' 'index.php' 'snap/2026/06/11/index.php' 'snap/2026/06/11/evil.php.jpg' 'snap/2026/06/11/notes.txt'; do
    code="$(http "$u")"
    if [ "$code" = "500" ]; then
        printf '  ok    500  %s\n' "${u:-la page}"
    else
        printf '  ECHEC %-3s  %s  (500 attendu : sans mod_rewrite le site doit rester fermé)\n' "$code" "${u:-la page}"
        echec=1
    fi
done
arreter

echo
if [ "$echec" -eq 0 ]; then
    echo "Tout passe."
else
    echo "Au moins une attente est violée — voir doc/SECURITE.md §2.1 avant de déployer."
fi
exit "$echec"
