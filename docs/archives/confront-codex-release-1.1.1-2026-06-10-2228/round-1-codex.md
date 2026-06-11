# Round 1 - Revue Codex

## 1. Ce que j'approuve

### Diagnostic du bug commentaires

Le diagnostic est solide. Le code confirme que l'intégration commentaires accroche `preprocess_comment` dans `includes/integrations/class-wp-comments.php`, donc elle s'exécute sur des créations de commentaires qui ne viennent pas forcément du formulaire public rendu par `comment_form_after_fields` / `comment_form_logged_in_after`. Une réponse modérateur construite côté admin peut donc arriver dans `validate()` sans champ `cf-turnstile-response`, puis échouer dans `Abstract_Integration::passes()`.

Le fait de ne pas valider les pingbacks/trackbacks était déjà correct, et le nouveau bypass se place au bon endroit: avant l'appel à `passes()`, sans changer la vérification serveur des vrais formulaires protégés.

### Absence de contournement public évident

Je ne vois pas de bypass public anonyme avec la condition actuelle. `wp_doing_ajax()` seul serait beaucoup trop faible, mais combiné à `current_user_can( 'moderate_comments' )`, un visiteur non connecté, un abonné, ou un auteur sans cette capacité ne passe pas. Pour le cas décrit - réponse depuis le dashboard ou la barre d'admin par un modérateur - l'idée de ne pas exiger un token impossible à produire est correcte.

### Injection du token non cassée par le `callback`

L'ajout d'un `callback` de succès dans `assets/js/turnstile.js` ne me choque pas. Le plugin utilise le rendu explicite, et Cloudflare garde un mécanisme séparé de champ caché `cf-turnstile-response` avec `response-field` activé par défaut. Le callback reçoit le token; il ne remplace pas, par lui-même, l'injection automatique du champ caché. Sur ce point, l'argument du plan est bon.

### Arrêt du `safeReset()` au cap

Le raisonnement local est juste: dans ce code, `safeReset()` appelle `turnstile.reset()`, donc continuer à le faire au moment où l'on veut abandonner relancerait le widget. Pour le scénario testé de clé liée au mauvais domaine, ne plus appeler `safeReset()` au cap est probablement ce qui supprime le clignotement et le flot rapide de requêtes.

### Version source globalement cohérente

Dans les fichiers source principaux, le bump est cohérent: header du plugin, `CWEBTS_VERSION`, `Stable tag`, changelog du `readme.txt`, `CHANGELOG.md`, et header `Project-Id-Version` du `.pot` sont tous à `1.1.1`. `php -l`, `node --check` et `php tests/run-tests.php` passent localement; la suite annonce bien `67 run, 67 passed`.

## 2. Ce que je désapprouve

### Le bypass commentaires est plus large que ce que le plan prétend

Je ne dirais pas que c'est une faille publique majeure, mais la condition actuelle ne cible pas seulement `replyto-comment`. Elle cible toute exécution AJAX où l'utilisateur courant possède `moderate_comments`:

```php
if ( wp_doing_ajax() && current_user_can( 'moderate_comments' ) ) {
	return $commentdata;
}
```

`wp_doing_ajax()` signifie surtout que la constante `DOING_AJAX` est active; ce n'est pas une preuve que l'on est dans le handler core `replyto-comment`, ni que le formulaire admin sans widget est le chemin réellement emprunté. Conséquence: un formulaire de commentaire AJAX frontal fourni par un thème/plugin, soumis par un utilisateur ayant `moderate_comments`, pourrait aussi sauter Turnstile.

Ce n'est probablement pas grave dans le modèle de menace usuel, car un utilisateur avec `moderate_comments` est déjà un acteur de confiance pour la modération. Mais la justification "seulement le chemin cassé" est trop forte. Si on veut que le code corresponde au commentaire, il faut borner par action:

```php
$ajax_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
if ( wp_doing_ajax() && 'replyto-comment' === $ajax_action && current_user_can( 'moderate_comments' ) ) {
	return $commentdata;
}
```

Je ne rajouterais pas forcément une vérification de nonce ici: le handler WordPress `replyto-comment` doit déjà l'avoir passée avant d'appeler `wp_new_comment()`. En revanche, vérifier l'action est peu coûteux et rend le bypass beaucoup plus fidèle au bug corrigé.

### Le cap de retry repose sur une hypothèse Cloudflare non documentée

Le point le plus fragile du diff est l'idée que `return false` dans `error-callback` stoppe le retry automatique de Turnstile. La documentation Cloudflare que j'ai vérifiée documente `error-callback` comme notification d'erreur, et documente séparément `retry` / `retry-interval` comme contrôle du retry automatique. Je n'ai pas vu de contrat officiel disant que la valeur de retour de `error-callback` désactive le retry.

Donc je ne valide pas catégoriquement "le cap stoppe vraiment le martèlement" pour tous les cas. Je valide seulement: "le cap stoppe les resets manuels provoqués par notre callback". Si Turnstile garde son `retry: auto` par défaut, un cas retryable peut continuer à retenter sans passer par `safeReset()`, possiblement moins vite, mais indéfiniment.

La position du plan contre `retry: 'never'` est trop binaire. `retry: 'never'` ne veut pas dire "aucune récupération réseau possible" si le plugin fait lui-même deux `turnstile.reset()` manuels dans `error-callback`. Au contraire, la combinaison la plus défendable est:

```js
retry: 'never',
'error-callback': function () {
	var n = ( el.__tfErrors || 0 ) + 1;
	el.__tfErrors = n;
	if ( n < 3 ) {
		safeReset( el );
	}
}
```

Avec ça, le plugin possède réellement le budget de retry. Sans ça, il dépend d'un comportement implicite de l'API Cloudflare.

### Le commentaire "successful render" est imprécis

Le nouveau `callback` ne signifie pas "render réussi"; il signifie "challenge complété et token produit". Ce n'est pas un bug fonctionnel, mais le commentaire peut induire en erreur. Pour le compteur, ce que l'on veut vraiment dire est: "un token réussi prouve que le widget a récupéré".

### Le `.pot` n'est pas réellement régénéré

Le diff ne fait que passer `Project-Id-Version` à `1.1.1`. Or l'ajout de commentaires PHP décale au moins la référence de `Comment Submission Failure`: le `.pot` pointe encore vers `includes/integrations/class-wp-comments.php:63`, alors que la chaîne est maintenant plus bas dans le fichier. Runtime sans impact, mais si le fichier `.pot` est maintenu comme artefact généré, il devrait être régénéré plutôt que modifié à la main.

## 3. Ce qui manque

### Tests ciblés commentaires

La suite actuelle ne couvre pas `WP_Comments::validate()`. Il manque au minimum un test ou protocole manuel explicite pour ces cas:

- commentaire public sans token rejeté;
- commentaire public avec token valide accepté;
- pingback/trackback toujours ignoré;
- AJAX `replyto-comment` avec `moderate_comments` accepté;
- AJAX autre que `replyto-comment` avec `moderate_comments` selon la décision finale: accepté si bypass large assumé, rejeté si action bornée.

Le test manuel staging est utile, mais il ne protège pas contre une régression évidente sur cette condition.

### Preuve plus forte sur le retry Turnstile

Il manque une preuve déterministe que le retry s'arrête côté Cloudflare, pas seulement que notre `safeReset()` cesse. Vu la doc officielle, je demanderais soit:

- un changement vers `retry: 'never'` plus retries manuels bornés;
- soit une preuve navigateur claire montrant qu'après le troisième `error-callback`, il n'y a plus aucune requête Cloudflare sur plusieurs intervalles de retry, pour au moins un code retryable.

Le test clé/domaine incompatible est nécessaire, mais pas suffisant pour généraliser à tous les échecs persistants.

### UX quand le widget abandonne

Après le cap, le code abandonne silencieusement. C'est acceptable pour une release de maintenance si l'objectif est uniquement de stopper le bruit réseau, mais il faut reconnaître le comportement: un utilisateur légitime peut rester avec un widget en erreur et aucune action de récupération autre que recharger la page. Ce n'est pas forcément bloquant, mais ce n'est pas traité.

### Packaging WordPress.org incomplet dans le workspace

Le code source est bumpé, mais le packaging de release n'est pas complet dans l'arbre actuel:

- aucun zip `cweb-form-protection-turnstile-elementor-1.1.1.zip` n'existe; seuls `1.0.1` et `1.1.0` sont présents;
- `.svn-wporg/trunk` est encore en `1.1.0`;
- `.svn-wporg/tags/1.1.1` n'existe pas.

Si `.svn-wporg` est seulement un miroir de déploiement non commité, ce n'est pas un problème Git. Mais pour la checklist release, il manque clairement "sync trunk SVN depuis le dossier plugin", "créer le tag SVN `1.1.1`", et "rebuild/contrôle du zip".

### `Tested up to`

`readme.txt` garde `Tested up to: 7.0`. Je ne dis pas que c'est faux, mais le plan devrait indiquer si cette valeur a été revalidée ou volontairement laissée telle quelle. Pour une release WordPress.org, c'est un champ de packaging à confirmer, pas juste à ignorer.

## 4. Ce que je remettrais en question

### Faut-il rester sur `preprocess_comment`?

Le choix du garde minimal est raisonnable pour une 1.1.1. Mais `preprocess_comment` reste un hook très large: il couvre plus que le formulaire public que le plugin rend. C'est la cause du bug actuel, et il peut y avoir d'autres chemins sans widget: importeurs, XML-RPC, REST, plugins de commentaires AJAX, outils internes. Si la promesse produit est "protéger le formulaire de commentaire public", un hook plus proche du submit frontal mérite débat à moyen terme.

Pour cette release, je ne bloquerais pas sur ce point si on borne le bypass à `replyto-comment`. Je bloquerais seulement si on continue à présenter le bypass actuel comme strictement limité au chemin admin décrit.

### Le nombre `3`

Deux resets puis abandon est un compromis acceptable, mais arbitraire. Ce n'est pas grave pour une maintenance release, à condition que le code possède vraiment le retry. Avec `retry: auto`, le nombre `3` donne une impression de borne plus forte que ce que le code garantit.

### Reset du compteur sur chaque token réussi

Je suis plutôt d'accord avec cette décision: on veut éviter que trois erreurs espacées sur une longue session bloquent définitivement un utilisateur. Le seul cas discutable est un widget qui alterne succès et erreur en boucle: le compteur ne montera jamais. Je ne considérerais pas ça bloquant, car un token réussi signifie que le formulaire peut fonctionner, mais il faut éviter de vendre le cap comme une protection contre toute boucle alternée.

### Verbosité du changelog public

Le changelog est exact, mais très détaillé pour un `readme.txt` WordPress.org. Ce n'est pas un défaut technique. Je le garderais si l'objectif est transparence support; je le raccourcirais si l'objectif est lisibilité annuaire.

## Verdict technique

Le correctif PHP est globalement bon, mais je recommanderais de restreindre le bypass à `action=replyto-comment` pour aligner le code avec l'intention annoncée.

Le correctif JS n'est pas assez solide tel qu'argumenté. Il corrige probablement le flot rapide causé par les `safeReset()` manuels, mais il ne prouve pas qu'il borne le retry automatique de Turnstile. Pour une release propre, je changerais la stratégie vers `retry: 'never'` plus deux resets manuels, ou je demanderais une preuve navigateur sur un cas d'erreur retryable.

Le versionnage source est cohérent, mais le packaging de release n'est pas complet tant que le zip `1.1.1`, le trunk SVN et le tag SVN `1.1.1` ne sont pas produits/synchronisés. Le `.pot` devrait aussi être régénéré si on le maintient comme artefact généré.
