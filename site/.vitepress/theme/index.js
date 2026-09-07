// `theme-without-fonts` plutôt que `theme` : le thème par défaut embarque Inter en quatorze
// fichiers (cyrillique, grec et vietnamien compris) qu'aucune page de ce corpus n'emploie.
// L'application n'embarque aucune police non plus (index.php : `system-ui, -apple-system, …`) :
// le site en fait autant, et ne déclenche donc aucun appel réseau vers un tiers.
import DefaultTheme from 'vitepress/theme-without-fonts'
import './custom.css'

export default DefaultTheme
