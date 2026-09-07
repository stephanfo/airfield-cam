// Pose un lien symbolique node_modules à la racine du dépôt, vers celui de site/.
//
// POURQUOI. npm ne vit que sous site/ : la racine du dépôt reste sans dépendance ni build, c'est
// la promesse du projet (CONTRIBUTING.md). Mais la documentation est construite depuis les
// Markdown de cette racine (`srcDir: '..'`), et Vite résout les imports d'un module depuis
// l'emplacement de ce module. Sans node_modules à la racine, le build s'arrête sur :
//
//     [vite]: Rollup failed to resolve import "vue/server-renderer" from "CONTRIBUTING.md"
//
// Le lien est un ARTEFACT DE BUILD, gitignoré au même titre que _site/ : il n'ajoute aucune
// dépendance à l'application, et rien de ce qu'il pointe n'est déployé sur l'hébergement.
import { existsSync, symlinkSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const racine = fileURLToPath(new URL('..', import.meta.url))
const lien = `${racine}node_modules`

if (existsSync(lien)) {
  process.exit(0)
}

// `junction` est ignoré hors Windows ; là-bas, il évite d'exiger les droits administrateur que
// réclame un lien symbolique de dossier.
symlinkSync('site/node_modules', lien, 'junction')
console.log('Lien node_modules -> site/node_modules posé à la racine du dépôt.')
