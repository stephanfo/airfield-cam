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
import { existsSync, lstatSync, symlinkSync, unlinkSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

// Chemins ABSOLUS des deux côtés : ce script est appelé tantôt depuis la racine du dépôt
// (build-local.sh), tantôt depuis site/ (les hooks npm `predoc:*`). Un chemin relatif serait
// résolu contre le répertoire courant — et donnerait un lien cassé une fois sur deux.
const cible = fileURLToPath(new URL('node_modules', import.meta.url))
const lien = fileURLToPath(new URL('../node_modules', import.meta.url))

try {
  // `existsSync` suit le lien : vrai ici veut dire « un dossier utilisable est en place ».
  if (existsSync(lien)) {
    process.exit(0)
  }
  // Rien d'utilisable, mais `lstat` répond : c'est un lien cassé (site/node_modules supprimé
  // puis réinstallé ailleurs, par exemple). Le laisser ferait échouer symlinkSync sur EEXIST.
  lstatSync(lien)
  unlinkSync(lien)
} catch {
  // Rien à cet emplacement : on crée.
}

// `junction` est ignoré hors Windows ; là-bas, il évite d'exiger les droits administrateur que
// réclame un lien symbolique de dossier.
symlinkSync(cible, lien, 'junction')
console.log('Lien node_modules -> site/node_modules posé à la racine du dépôt.')
