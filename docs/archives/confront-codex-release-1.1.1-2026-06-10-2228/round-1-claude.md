# Round 1 — Plan / diff à valider : release 1.1.1

## Contexte

Plugin WordPress **CWeb Form Protection with Turnstile for Elementor Forms**
(slug `cweb-form-protection-turnstile-elementor`, namespace PHP `CWebTS`), publié
sur WordPress.org, actuellement en **1.1.0**. On prépare une release **1.1.1** de
maintenance qui regroupe **deux correctifs** indépendants. Le diff est **dans
l'arbre de travail, non commité**. Les deux correctifs ont déjà été testés
manuellement sur un site de staging ; cette revue vise à valider la correction et
le packaging avant commit + déploiement (GitHub + SVN WordPress.org).

Le code source réel du plugin est dans le sous-dossier
`cweb-form-protection-turnstile-elementor/` à la racine du repo. Tu as accès au
workspace : vérifie les hypothèses ci-dessous directement dans le code si besoin
(notamment `includes/integrations/class-abstract-integration.php`,
`includes/integrations/class-wp-comments.php`, `assets/js/turnstile.js`).

---

## Correctif #1 — Réponses aux commentaires bloquées en admin

### Symptôme
Avec le réglage « Protéger les commentaires » actif, **répondre à un commentaire
depuis l'admin WordPress** (tableau de bord ou barre d'admin) renvoie l'erreur de
challenge Turnstile (« vérifiez que vous n'êtes pas un robot », `wp_die` 403). Il
faut désactiver le plugin pour pouvoir répondre.

### Cause racine
L'intégration commentaires valide via le filtre `preprocess_comment` :

```php
add_filter( 'preprocess_comment', array( $this, 'validate' ) );
```

`preprocess_comment` se déclenche pour **toutes** les insertions de commentaire, y
compris la réponse postée depuis l'admin via l'action AJAX `replyto-comment`.
Chaîne vérifiée (WordPress 6.x) :

1. Clic « Répondre » → requête AJAX `replyto-comment` vers `admin-ajax.php`
2. `wp_ajax_replyto_comment()` → `wp_new_comment( $commentdata )`
3. `wp_new_comment()` → `apply_filters( 'preprocess_comment', $commentdata )`
4. `WP_Comments::validate()` s'exécute → aucun widget Turnstile n'a été rendu dans
   ce formulaire de réponse (construit en JS côté admin), donc **aucun jeton**
   n'est envoyé → `passes()` faux → `wp_die()` 403.

Le plugin tiers `simple-cloudflare-turnstile` ne souffre pas de ce bug : il valide
via `pre_comment_on_post` (front-end) **et** garde `if ( is_admin() ) return;`.

### Correctif appliqué
Dans `includes/integrations/class-wp-comments.php::validate()`, juste après le
bloc qui laisse passer pingbacks/trackbacks :

```php
		// Replies posted from the dashboard or admin bar go through the
		// replyto-comment AJAX action: WordPress builds the comment server-side
		// without ever rendering a Turnstile widget, so no token is sent. Yet
		// wp_new_comment() still runs preprocess_comment, so without this bypass
		// every moderator reply is rejected with the challenge error. The public
		// comment form (which does render a widget) is unaffected; only trusted
		// users who can moderate comments skip the check, and only in admin AJAX.
		if ( wp_doing_ajax() && current_user_can( 'moderate_comments' ) ) {
			return $commentdata;
		}

		if ( ! $this->passes() ) {
			wp_die( /* … inchangé … */ );
		}

		return $commentdata;
```

### Justification de la condition
- `wp_doing_ajax() && current_user_can( 'moderate_comments' )` ne vise que le
  chemin cassé : un membre de l'équipe qui répond depuis l'admin (réponses
  tableau de bord ET barre d'admin passent par l'AJAX `replyto-comment`).
- Le formulaire public continue d'afficher le widget et d'être vérifié comme
  avant : un visiteur public n'est jamais en contexte `wp_doing_ajax()` admin avec
  la capacité `moderate_comments`.
- Même esprit que le correctif 1.0.1 « mot de passe oublié WooCommerce » : on
  n'applique pas un contrôle impossible à passer sur un formulaire où le widget
  n'est pas rendu.

### Alternative écartée
Basculer la validation vers `pre_comment_on_post` (comme le plugin tiers)
éviterait nativement le contexte admin, mais c'est plus invasif (hook différent,
signature différente) et `pre_comment_on_post` ne couvre que les commentaires sur
articles, pas toutes les voies. Le garde minimal est suffisant et plus sûr à
court terme.

---

## Correctif #2 — Borner l'auto-retry du widget Turnstile

### Symptôme
Sur une erreur de rendu **persistante** (clé de site verrouillée sur un autre
domaine, ou autre échec dur), le widget Turnstile réessayait **à l'infini** :
flot de `400 Bad Request` vers `challenges.cloudflare.com`, clignotement du widget
toutes les ~0,5 s, `postMessage origin mismatch` en console. Constaté sur staging
(clé de prod utilisée sur le domaine de staging). Pas la cause d'un bug
fonctionnel, mais mauvaise hygiène réseau.

### État initial (`assets/js/turnstile.js`, `buildOptions()`)
Le widget est rendu en **mode explicite** (`turnstile.render(el, options)`).
`buildOptions()` ne fournissait **pas** de `callback` de succès, seulement
`expired-callback`, `timeout-callback` et :

```js
'error-callback': function () {
	safeReset( el );   // safeReset() => turnstile.reset() => relance un challenge
	return true;       // auto-retry infini
},
```

### Correctif appliqué
Ajout d'un `callback` de succès qui remet le compteur à zéro, et bornage de
`error-callback` :

```js
callback: function () {
	// A successful render means the widget recovered, so clear the error
	// budget: errors spread across a long session must never end up
	// locking out a legitimate visitor.
	el.__tfErrors = 0;
},
'expired-callback': function () {
	safeReset( el );
},
'timeout-callback': function () {
	safeReset( el );
},
'error-callback': function () {
	// A persistent hard failure (e.g. a site key locked to another
	// domain) would otherwise retry forever — a flood of 400s to
	// Cloudflare and a widget flickering every ~0.5s. Allow a couple of
	// resets to ride out a transient network hiccup, then stop; resetting
	// is what re-triggers the challenge, so once we give up we must NOT
	// reset again or the hammering continues. A successful render
	// (callback above) clears the count.
	var n = ( el.__tfErrors || 0 ) + 1;
	el.__tfErrors = n;
	if ( n < 3 ) {
		safeReset( el );
		return true;
	}
	return false;
}
```

### Décisions et justifications
1. **Reset du compteur sur succès** (`callback`) : le cap devient « 3 erreurs
   **consécutives** sans rendu réussi entre-temps » plutôt que « 3 erreurs sur
   toute la vie de la page ». Vise l'échec dur persistant sans risquer de bloquer
   un visiteur légitime sur réseau capricieux.
2. **Pas de `safeReset()` au cap** : c'est `safeReset()` → `turnstile.reset()` qui
   **relance** le challenge. Sur un échec dur, reset → re-render → re-erreur. Si
   on gardait `safeReset()` inconditionnel et qu'on changeait juste la valeur de
   retour, le widget continuerait à se réinitialiser tout seul et **marteler
   quand même** Cloudflare. Donc : reset+retry pour les 2 premières erreurs
   (récupération réseau), puis on coupe vraiment (ni reset, ni retry).
3. **Injection du token préservée** : en rendu explicite, Cloudflare injecte le
   champ caché `cf-turnstile-response` lui-même ; le `callback` de succès est une
   simple notification supplémentaire et ne perturbe pas cette injection.

### À NE PAS faire (et qu'on n'a pas fait)
`retry: 'never'` ou `return false` d'emblée : ça casserait la récupération après
une coupure réseau passagère chez un vrai utilisateur. Le but est de **borner**,
pas d'interdire.

---

## Packaging / versionnage (1.1.0 → 1.1.1)

- `cweb-form-protection-turnstile-elementor.php` : en-tête `Version: 1.1.1` +
  `define( 'CWEBTS_VERSION', '1.1.1' )`.
- `readme.txt` : `Stable tag: 1.1.1` + 2 lignes de changelog `= 1.1.1 =` +
  entrée `== Upgrade Notice ==`.
- `CHANGELOG.md` : section `## [1.1.1] — 2026-06-10` avec les deux entrées `Fixed`.
- `languages/*.pot` : `Project-Id-Version: … 1.1.1` (aucune chaîne traduisible
  nouvelle — le correctif #1 n'ajoute qu'un commentaire PHP, le #2 que du JS).

Suite de tests PHP existante : **67/67** au vert (elle ne couvre pas
l'intégration commentaires ni le JS). `php -l` OK sur le fichier modifié,
`node --check` OK sur le JS.

---

## Points à challenger en priorité

1. **Bypass commentaires** : la condition `wp_doing_ajax() &&
   current_user_can('moderate_comments')` affaiblit-elle la protection publique
   ou ouvre-t-elle un contournement ? Existe-t-il un chemin où un acteur non
   fiable se retrouve en `wp_doing_ajax()` avec `moderate_comments` ? Faut-il
   restreindre davantage (vérifier l'action `replyto-comment`, un nonce, autre
   capacité) ou est-ce inutilement large/étroit ?

2. **Cap de retry** : le cap à 3 et surtout l'arrêt du `safeReset()` au cap
   stoppent-ils réellement le martèlement ? Y a-t-il un cas où Turnstile relance
   tout seul malgré `return false` (donc le `el.__tfErrors` croît sans que reset
   soit appelé, et on reste bloqué en boucle) ? Le `callback` de succès remet-il
   le compteur à zéro au bon moment, ou peut-il masquer un widget qui alterne
   succès/erreur indéfiniment ? L'ajout d'un `callback` perturbe-t-il l'injection
   de `cf-turnstile-response` ou le flux de reset après échec AJAX Elementor
   (`resetWithin`) ?

3. **Packaging** : ai-je oublié quelque chose dans le versionnage (fichiers où la
   version apparaît, cohérence des numéros, `Tested up to`, zip de distribution,
   etc.) ?

---

## Test déjà effectué (staging)
Les deux correctifs ont été appliqués et testés manuellement sur un site de
staging : réponse aux commentaires depuis l'admin OK avec protection active ;
formulaire de commentaire public toujours protégé ; et pour le widget, plus de
flot de 400 sur le scénario clé/domaine incompatible.
