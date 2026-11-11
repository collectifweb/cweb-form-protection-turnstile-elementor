# Leçons — CWeb Form Protection with Turnstile

## Elementor : toujours tester une deuxième visite (2026-11-11)

**Piège.** Elementor 4 met en cache le rendu de chaque formulaire (méta
`_elementor_element_cache`, 24 h par défaut). Une validation qui ne regarde que la
première visite après un enregistrement ou un vidage du cache voit toujours un
formulaire « frais ». La 1.2.2 a été validée ainsi en août : le champ Turnstile
par formulaire, le mode par défaut de l'extension, perdait son widget dès la
deuxième visite (mesuré avec Elementor 4.2.2, la version d'août), et nos
validations ne l'ont pas vu.

**Règle.** Tout test d'un formulaire Elementor compte au moins deux visites, la
seconde servie par le cache (vérifier que la méta existe avant). Même chose pour
un changement de réglage : tester la visite qui suit l'enregistrement sur une page
déjà en cache. Le scénario navigateur et `tests/MANUAL-PROTOCOL.md` (section B)
le font maintenant.

**Mécanisme à retenir.** Sur un cache valide, Elementor n'appelle ni le rendu des
champs ni le filtre `elementor/widget/render_content`. Il réenfile les scripts
mémorisés au premier rendu. Un script enregistré seulement pendant le rendu
n'existe donc pas sur ces visites : il faut l'enregistrer sur `wp_enqueue_scripts`.

## Elementor : un modèle réutilisé se teste seul sur une autre page (2026-11-11)

**Piège.** Le cache d'un document Elementor ne retient que les scripts que ce
document ajoute à la file. Un modèle mis en cache sur une page qui avait déjà
chargé nos scripts n'en retient aucun ; affiché seul ailleurs, son formulaire
reste vide. Deux visites de la même page ne le montrent pas. C'est la relecture
Codex qui l'a trouvé, pas nos tests.

**Règle.** Tester un formulaire protégé dans un modèle réutilisé : d'abord sur une
page qui charge déjà les scripts, puis seul sur une autre page (étape du scénario
`tests/e2e/`). Un correctif sous forme de filtre se prouve en le neutralisant :
une extension « mu » temporaire qui l'annule doit faire échouer l'étape, et elle
seule.

## Tests unitaires : appeler les fonctions réellement branchées (2026-11-11)

**Piège.** Vérifier qu'un crochet existe puis appeler la méthode directement laisse
passer une faute de nom dans `add_action()` : le test passe, le site ne fait rien.

**Règle.** Les doublures `add_action`/`add_filter` de `tests/bootstrap.php` gardent la
fonction branchée : l'appeler via `tf_hook(...)['callback']`. Après un nouveau bloc
de tests, casser volontairement le code dans une copie pour vérifier qu'il échoue.

## Git : pousser chaque commit, même sous la consigne du Chef (2026-11-11)

**Piège.** Après la publication de la 1.2.3, j'ai laissé un commit de documentation en
local, en lisant « rien de visible sans accord » comme couvrant aussi le push.

**Règle.** Un commit est poussé tout de suite, sans demander (consigne écrite dans
`Le Caribou/CLAUDE.md`). Le « oui » d'Alexandre reste nécessaire pour publier une version
(étiquette, release GitHub, envoi SVN).

## Plugin Check : relancer la version du jour (2026-11-11)

**Piège.** « Plugin Check : No errors found » datait de la 2.0.0. La 2.1.0 a
élargi une règle trois jours après la publication de la 1.2.2, et le même code
sortait 3 erreurs.

**Règle.** À chaque rendez-vous, relancer Plugin Check dans sa dernière version.
Quand une erreur apparaît, rejouer l'ancienne version sur le même code pour savoir
si c'est l'outil ou le code qui a changé.

## wp-env : pièges de la recette de test (2026-11-11)

- Le WordPress monté est dans `~/.wp-env/<env>/wordpress-<version>/`, pas
  `WordPress/`. Une extension « mu » ou un `debug.log` se lisent là.
- `wp plugin install --force` sur une extension montée par wp-env vide le dossier
  monté, puis échoue (le conteneur n'a pas accès au réseau). Télécharger le zip sur
  le poste et le décompresser dans le dossier monté.
- Changer `permalink_structure` par `update_option()` puis `flush_rewrite_rules()`
  dans le même appel garde les anciennes règles : utiliser
  `wp rewrite structure '/%postname%/' --hard`.
- Le conteneur n'a pas de serveur de courriel : sans extension « mu » qui court-
  circuite `pre_wp_mail`, l'envoi du mot de passe perdu échoue après notre contrôle
  et ressemble à un échec du test.
- WooCommerce 11 affiche ses messages dans `.wc-block-components-notice-banner`,
  plus `.woocommerce-message`.
- L'indicateur de force du mot de passe de WooCommerce change de hauteur quand le
  champ perd le focus : un clic automatique sur « Register » tombe à côté. Quitter
  le champ (Tab) et attendre avant de cliquer.
- Codex : `-m gpt-5.5` est refusé avec le compte ChatGPT ; ne pas passer `-m`,
  le modèle configuré dans `~/.codex/config.toml` est pris.
